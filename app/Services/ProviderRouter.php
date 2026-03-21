<?php

namespace App\Services;

use App\Models\ApiProvider;
use App\Models\ApiSetting;
use App\Models\Country;
use App\Models\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client as TwilioClient;

/**
 * Smart Provider Router
 *
 * Handles multi-provider number allocation with:
 *  - Priority-based routing
 *  - Automatic failover
 *  - Success rate tracking
 *  - Provider performance metrics
 *
 * Routing order:
 *  1. Internal number pool (pre-fetched numbers in phone_numbers table)
 *  2. Active API providers sorted by priority → success_rate → cost
 */
class ProviderRouter
{
    /** Max providers to attempt before giving up */
    const MAX_RETRIES = 4;

    /**
     * Allocate a number for a given country.
     *
     * Returns: ['phone_number', 'provider_sid', 'provider_id', 'provider_slug', 'response_ms', 'routing_log']
     * Throws: \Exception if all providers fail
     */
    public function allocateNumber(Country $country, ?string $serviceSlug = null): array
    {
        $routingLog = [];
        $attempt = 0;

        // ─── Step 1: Check internal number pool ───
        $internal = $this->tryInternalPool($country, $serviceSlug);
        if ($internal) {
            $routingLog[] = ['source' => 'internal_pool', 'status' => 'success', 'ms' => 0];
            return array_merge($internal, ['routing_log' => $routingLog, 'retry_count' => 0]);
        }
        $routingLog[] = ['source' => 'internal_pool', 'status' => 'no_numbers'];

        // ─── Step 2: Try active providers in routing order ───
        $providers = $this->getOrderedProviders();

        foreach ($providers as $provider) {
            if ($attempt >= self::MAX_RETRIES) break;
            $attempt++;

            $start = microtime(true);
            try {
                $result = $this->provisionFromProvider($provider, $country);
                $ms = (int) ((microtime(true) - $start) * 1000);

                // Track success
                $this->recordSuccess($provider, $ms);

                $routingLog[] = [
                    'provider' => $provider->slug,
                    'status'   => 'success',
                    'ms'       => $ms,
                ];

                return [
                    'phone_number'  => $result['phone_number'],
                    'provider_sid'  => $result['provider_sid'],
                    'provider_id'   => $provider->id,
                    'provider_slug' => $provider->slug,
                    'response_ms'   => $ms,
                    'routing_log'   => $routingLog,
                    'retry_count'   => $attempt,
                ];

            } catch (\Exception $e) {
                $ms = (int) ((microtime(true) - $start) * 1000);
                $this->recordFailure($provider, $ms);

                $routingLog[] = [
                    'provider' => $provider->slug,
                    'status'   => 'failed',
                    'error'    => $e->getMessage(),
                    'ms'       => $ms,
                ];

                Log::warning("ProviderRouter: {$provider->slug} failed for {$country->code}: {$e->getMessage()}");
                continue; // Try next provider
            }
        }

        // All providers exhausted
        Log::error("ProviderRouter: All providers exhausted for country {$country->code}", $routingLog);
        throw new \Exception(
            'No numbers available for the selected country right now. Please try again later.'
        );
    }

    /**
     * Release a provisioned number back to the provider.
     */
    public function releaseNumber(string $providerSid, ?int $providerId = null, ?string $providerSlug = null): void
    {
        // Determine which provider to release from
        $provider = null;
        if ($providerId) {
            $provider = ApiProvider::find($providerId);
        } elseif ($providerSlug) {
            $provider = ApiProvider::where('slug', $providerSlug)->first();
        }

        if (!$provider) {
            // Legacy: fall back to global Twilio credentials
            $this->releaseTwilioLegacy($providerSid);
            return;
        }

        try {
            match ($provider->type) {
                'twilio'  => $this->releaseTwilioNumber($provider, $providerSid),
                'telnyx'  => $this->releaseTelnyxNumber($provider, $providerSid),
                'plivo'   => $this->releasePlivoNumber($provider, $providerSid),
                'vonage'  => $this->releaseVonageNumber($provider, $providerSid),
                default   => Log::warning("No release handler for provider type: {$provider->type}"),
            };
        } catch (\Exception $e) {
            Log::warning("Failed to release number via {$provider->slug}: {$e->getMessage()}");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  INTERNAL POOL
    // ═══════════════════════════════════════════════════════════════

    private function tryInternalPool(Country $country, ?string $serviceSlug = null): ?array
    {
        $baseQuery = PhoneNumber::where('country_id', $country->id)
            ->where('status', 'available')
            ->whereColumn('times_used', '<', 'max_uses');

        $number = null;

        // First: try numbers that are explicitly tagged for this service
        if ($serviceSlug) {
            $number = (clone $baseQuery)
                ->whereHas('services', function ($q) use ($serviceSlug) {
                    $q->where('name', 'like', "%{$serviceSlug}%");
                })
                ->orderBy('times_used', 'asc')
                ->first();
        }

        // Fallback: any available number for this country (untagged or universal)
        if (!$number) {
            $number = $baseQuery->orderBy('times_used', 'asc')->first();
        }

        if (!$number) return null;

        // Reserve it
        $number->update([
            'status'     => 'in_use',
            'times_used' => $number->times_used + 1,
            'reserved_at' => now(),
        ]);

        return [
            'phone_number'  => $number->phone_number,
            'provider_sid'  => $number->provider_sid,
            'provider_id'   => null,
            'provider_slug' => 'internal',
            'response_ms'   => 0,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  ROUTING ORDER
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get providers in routing order based on global routing mode.
     */
    private function getOrderedProviders()
    {
        $mode = ApiSetting::getValue('routing_mode', 'priority');

        $query = ApiProvider::where('is_active', true)
            ->whereNotNull('credentials');

        return match ($mode) {
            // Cheapest first (by cost_multiplier), then priority
            'cheapest' => $query->orderBy('cost_multiplier', 'asc')
                                ->orderBy('priority', 'asc')
                                ->get()
                                ->filter(fn($p) => $p->isConfigured()),

            // Smart: weighted score = (success_rate * 0.6) + ((1/cost_multiplier) * 0.2) + ((1/priority) * 0.2)
            'smart' => $query->get()
                             ->filter(fn($p) => $p->isConfigured())
                             ->sortByDesc(function ($p) {
                                 $sr = $p->success_rate ?: 50;
                                 $cm = $p->cost_multiplier ?: 1;
                                 $pr = $p->priority ?: 10;
                                 return ($sr * 0.6) + ((100 / $cm) * 0.2) + ((100 / $pr) * 0.2);
                             }),

            // Default: priority (lower number = higher priority)
            default => $query->orderBy('priority', 'asc')
                             ->orderBy('success_rate', 'desc')
                             ->get()
                             ->filter(fn($p) => $p->isConfigured()),
        };
    }

    // ═══════════════════════════════════════════════════════════════
    //  PROVIDER PROVISIONING (buy a number on-demand)
    // ═══════════════════════════════════════════════════════════════

    private function provisionFromProvider(ApiProvider $provider, Country $country): array
    {
        return match ($provider->type) {
            'twilio'  => $this->provisionTwilio($provider, $country),
            'telnyx'  => $this->provisionTelnyx($provider, $country),
            'plivo'   => $this->provisionPlivo($provider, $country),
            'vonage'  => $this->provisionVonage($provider, $country),
            '5sim'    => $this->provision5Sim($provider, $country),
            'smspva'  => $this->provisionSmsPva($provider, $country),
            default   => throw new \Exception("Unsupported provider type: {$provider->type}"),
        };
    }

    // ── Twilio ──
    private function provisionTwilio(ApiProvider $provider, Country $country): array
    {
        $twilio = new TwilioClient(
            $provider->getCredential('account_sid'),
            $provider->getCredential('auth_token')
        );

        $countryCode = $country->twilio_code ?: $country->code;

        // Try local → mobile → tollFree
        $phoneNumber = null;
        $types = ['local', 'mobile', 'tollFree'];

        foreach ($types as $type) {
            try {
                $available = $twilio->availablePhoneNumbers($countryCode)
                    ->{$type}->read(['smsEnabled' => true], 1);

                if (!empty($available)) {
                    $phoneNumber = $available[0]->phoneNumber;
                    break;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        if (!$phoneNumber) {
            throw new \Exception("No SMS-enabled numbers available in {$countryCode}");
        }

        // Purchase the number
        $webhookUrl = $provider->getSetting('webhook_url')
            ?: env('TWILIO_WEBHOOK_URL', rtrim(env('APP_URL'), '/') . '/api/webhook/sms');

        $createParams = ['phoneNumber' => $phoneNumber];

        // Only attach webhook for public URLs
        if ($webhookUrl && !preg_match('/(localhost|127\.0\.0\.\d+)/i', $webhookUrl)) {
            $createParams['smsUrl']    = $webhookUrl;
            $createParams['smsMethod'] = 'POST';
        }

        $purchased = $twilio->incomingPhoneNumbers->create($createParams);

        return [
            'phone_number' => $phoneNumber,
            'provider_sid' => $purchased->sid,
        ];
    }

    private function releaseTwilioNumber(ApiProvider $provider, string $sid): void
    {
        $twilio = new TwilioClient(
            $provider->getCredential('account_sid'),
            $provider->getCredential('auth_token')
        );
        $twilio->incomingPhoneNumbers($sid)->delete();
        Log::info("Released Twilio number SID {$sid} via provider {$provider->slug}");
    }

    private function releaseTwilioLegacy(string $sid): void
    {
        $twilioSid   = ApiSetting::getValue('twilio_account_sid', env('TWILIO_ACCOUNT_SID'));
        $twilioToken = ApiSetting::getValue('twilio_auth_token', env('TWILIO_AUTH_TOKEN'));
        if ($twilioSid && $twilioToken) {
            $twilio = new TwilioClient($twilioSid, $twilioToken);
            $twilio->incomingPhoneNumbers($sid)->delete();
            Log::info("Released Twilio number SID {$sid} via legacy credentials");
        }
    }

    // ── Telnyx ──
    private function provisionTelnyx(ApiProvider $provider, Country $country): array
    {
        $apiKey = $provider->getCredential('api_key');
        if (!$apiKey) throw new \Exception('Telnyx API key not configured');

        $countryCode = $country->code; // ISO 3166-1 alpha-2, e.g. "US", "CA", "GB"

        // Step 1: Search for available SMS-enabled numbers
        $searchResponse = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Accept'        => 'application/json',
        ])->get('https://api.telnyx.com/v2/available_phone_numbers', [
            'filter[country_code]'       => $countryCode,
            'filter[features]'           => 'sms',
            'filter[limit]'              => 5,
        ]);

        if (!$searchResponse->successful()) {
            $error = $searchResponse->json('errors.0.detail') ?? $searchResponse->body();
            throw new \Exception("Telnyx search failed: {$error}");
        }

        $available = $searchResponse->json('data', []);
        if (empty($available)) {
            throw new \Exception("No SMS-enabled Telnyx numbers available in {$countryCode}");
        }

        $phoneNumber = $available[0]['phone_number']; // E.164 format

        // Step 2: Purchase the number via number_orders
        $orderPayload = [
            'phone_numbers' => [
                ['phone_number' => $phoneNumber],
            ],
        ];

        // Attach messaging profile if configured
        $messagingProfileId = $provider->getSetting('messaging_profile_id');
        if ($messagingProfileId) {
            $orderPayload['messaging_profile_id'] = $messagingProfileId;
        }

        $orderResponse = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])->post('https://api.telnyx.com/v2/number_orders', $orderPayload);

        if (!$orderResponse->successful()) {
            $error = $orderResponse->json('errors.0.detail') ?? $orderResponse->body();
            throw new \Exception("Telnyx order failed: {$error}");
        }

        $orderData = $orderResponse->json('data', []);
        $orderId   = $orderData['id'] ?? null;

        // Step 3: If messaging profile configured, assign webhook URL to the number
        // Telnyx uses messaging profiles for webhook routing — the number
        // must be linked to a messaging profile that has the webhook URL set.
        // We'll do this after the number is provisioned by updating it.
        if ($messagingProfileId) {
            // Small delay to let the number order complete
            usleep(500000); // 0.5s

            $this->updateTelnyxNumberMessaging($apiKey, $phoneNumber, $messagingProfileId);
        }

        Log::info("Telnyx number provisioned: {$phoneNumber}, order ID: {$orderId}");

        return [
            'phone_number' => $phoneNumber,
            'provider_sid' => $orderId ?? $phoneNumber, // Use order ID or phone number as reference
        ];
    }

    /**
     * Update a Telnyx number to assign it to a messaging profile.
     */
    private function updateTelnyxNumberMessaging(string $apiKey, string $phoneNumber, string $messagingProfileId): void
    {
        try {
            // URL-encode the + in the phone number
            $encodedNumber = urlencode($phoneNumber);

            Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => 'application/json',
            ])->patch("https://api.telnyx.com/v2/phone_numbers/{$encodedNumber}", [
                'messaging_profile_id' => $messagingProfileId,
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to assign messaging profile to Telnyx number {$phoneNumber}: {$e->getMessage()}");
        }
    }

    /**
     * Release a Telnyx number — delete it from the account.
     */
    private function releaseTelnyxNumber(ApiProvider $provider, string $sid): void
    {
        $apiKey = $provider->getCredential('api_key');
        if (!$apiKey) {
            Log::warning("Cannot release Telnyx number — no API key for provider {$provider->slug}");
            return;
        }

        // $sid may be an order ID or a phone number (E.164).
        // If it looks like a phone number, delete it directly.
        // Otherwise, we need to look up the number from the order.
        $phoneNumber = $sid;

        if (!str_starts_with($sid, '+')) {
            // It's an order ID — try to get the phone number from the order
            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Accept'        => 'application/json',
                ])->get("https://api.telnyx.com/v2/number_orders/{$sid}");

                if ($response->successful()) {
                    $phoneNumbers = $response->json('data.phone_numbers', []);
                    $phoneNumber = $phoneNumbers[0]['phone_number'] ?? null;
                }
            } catch (\Exception $e) {
                Log::warning("Telnyx: Could not look up order {$sid}: {$e->getMessage()}");
                return;
            }
        }

        if (!$phoneNumber) {
            Log::warning("Telnyx: No phone number to release for SID {$sid}");
            return;
        }

        try {
            $encodedNumber = urlencode($phoneNumber);
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Accept'        => 'application/json',
            ])->delete("https://api.telnyx.com/v2/phone_numbers/{$encodedNumber}");

            if ($response->successful()) {
                Log::info("Released Telnyx number {$phoneNumber} via provider {$provider->slug}");
            } else {
                Log::warning("Telnyx release returned {$response->status()} for {$phoneNumber}");
            }
        } catch (\Exception $e) {
            Log::warning("Failed to release Telnyx number {$phoneNumber}: {$e->getMessage()}");
        }
    }

    // ── Plivo (placeholder) ──
    private function provisionPlivo(ApiProvider $provider, Country $country): array
    {
        throw new \Exception('Plivo provider integration coming soon');
    }

    private function releasePlivoNumber(ApiProvider $provider, string $sid): void
    {
        Log::info("Plivo release placeholder for SID {$sid}");
    }

    // ── Vonage (placeholder) ──
    private function provisionVonage(ApiProvider $provider, Country $country): array
    {
        throw new \Exception('Vonage provider integration coming soon');
    }

    private function releaseVonageNumber(ApiProvider $provider, string $sid): void
    {
        Log::info("Vonage release placeholder for SID {$sid}");
    }

    // ── 5SIM ──
    private function provision5Sim(ApiProvider $provider, Country $country): array
    {
        $apiKey = $provider->getCredential('api_key');
        if (!$apiKey) throw new \Exception('5SIM API key not configured');

        // TODO: Implement 5SIM API
        // GET https://5sim.net/v1/guest/products/{country}/{operator}
        // GET https://5sim.net/v1/user/buy/activation/{country}/{operator}/{product}
        throw new \Exception('5SIM provider integration coming soon');
    }

    // ── SMSPVA ──
    private function provisionSmsPva(ApiProvider $provider, Country $country): array
    {
        $apiKey = $provider->getCredential('api_key');
        if (!$apiKey) throw new \Exception('SMSPVA API key not configured');

        // TODO: Implement SMSPVA API
        throw new \Exception('SMSPVA provider integration coming soon');
    }

    // ═══════════════════════════════════════════════════════════════
    //  METRICS TRACKING
    // ═══════════════════════════════════════════════════════════════

    private function recordSuccess(ApiProvider $provider, int $responseMs): void
    {
        $total = $provider->total_requests + 1;
        $successes = $provider->total_successes + 1;

        // Rolling average response time
        $avgMs = $provider->avg_response_ms > 0
            ? (int) (($provider->avg_response_ms * $provider->total_requests + $responseMs) / $total)
            : $responseMs;

        $provider->update([
            'total_requests'  => $total,
            'total_successes' => $successes,
            'success_rate'    => round(($successes / $total) * 100, 2),
            'avg_response_ms' => $avgMs,
        ]);
    }

    private function recordFailure(ApiProvider $provider, int $responseMs): void
    {
        $total = $provider->total_requests + 1;
        $failures = $provider->total_failures + 1;
        $successes = $provider->total_successes;

        $provider->update([
            'total_requests' => $total,
            'total_failures' => $failures,
            'success_rate'   => $total > 0 ? round(($successes / $total) * 100, 2) : 0,
        ]);
    }
}
