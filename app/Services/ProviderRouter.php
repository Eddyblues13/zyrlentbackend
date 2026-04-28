<?php

namespace App\Services;

use App\Models\ApiProvider;
use App\Models\ApiSetting;
use App\Models\Country;
use App\Models\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client as TwilioClient;
use App\Services\SmsPoolService;

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
     * Returns: ['phone_number', 'provider_sid', 'provider_id', 'provider_slug',
     *           'response_ms', 'routing_log', 'retry_count', 'provider_order_id']
     * Throws: \Exception if all providers fail
     */
    public function allocateNumber(Country $country, ?string $serviceSlug = null, string $operator = "any"): array
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
                $result = $this->provisionFromProvider($provider, $country, $serviceSlug, $operator);
                $ms = (int) ((microtime(true) - $start) * 1000);

                // Track success
                $this->recordSuccess($provider, $ms);

                $routingLog[] = [
                    'provider' => $provider->slug,
                    'status'   => 'success',
                    'ms'       => $ms,
                ];

                return [
                    'phone_number'     => $result['phone_number'],
                    'provider_sid'     => $result['provider_sid'],
                    'provider_order_id' => $result['provider_order_id'] ?? null,
                    'provider_id'      => $provider->id,
                    'provider_slug'    => $provider->slug,
                    'response_ms'      => $ms,
                    'routing_log'      => $routingLog,
                    'retry_count'      => $attempt,
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

        // All providers exhausted — analyze errors for a helpful message
        Log::error("ProviderRouter: All providers exhausted for country {$country->code}", $routingLog);

        $errorMessage = $this->diagnoseAllocationFailure($routingLog, $providers);
        throw new \Exception($errorMessage);
    }

    /**
     * Analyze the routing log to provide a specific, user-friendly error message.
     */
    private function diagnoseAllocationFailure(array $routingLog, $providers): string
    {
        $errors = array_column(
            array_filter($routingLog, fn($entry) => ($entry['status'] ?? '') === 'failed'),
            'error'
        );
        $allErrors = strtolower(implode(' | ', $errors));

        // Check for insufficient provider balance
        if (str_contains($allErrors, 'not enough user balance') ||
            str_contains($allErrors, 'not enough balance') ||
            str_contains($allErrors, 'insufficient balance') ||
            str_contains($allErrors, 'low balance')) {
            return 'Service temporarily unavailable — provider balance is insufficient. Our team has been notified. Please try again later.';
        }

        // Check for no available numbers
        if (str_contains($allErrors, 'no free phones') ||
            str_contains($allErrors, 'no phones') ||
            str_contains($allErrors, 'no numbers available')) {
            return 'No numbers available for the selected country and service right now. Please try a different country or try again later.';
        }

        // Check for API key / auth issues
        if (str_contains($allErrors, 'unauthorized') ||
            str_contains($allErrors, 'invalid api key') ||
            str_contains($allErrors, 'api key not configured') ||
            str_contains($allErrors, '401')) {
            return 'Service temporarily unavailable — provider authentication issue. Our team has been notified.';
        }

        // Check for rate limiting
        if (str_contains($allErrors, 'rate limit') ||
            str_contains($allErrors, 'too many requests') ||
            str_contains($allErrors, '429')) {
            return 'Too many requests — please wait a moment and try again.';
        }

        // Check for product / country not supported
        if (str_contains($allErrors, 'bad country') ||
            str_contains($allErrors, 'bad product') ||
            str_contains($allErrors, 'bad operator') ||
            str_contains($allErrors, 'product not found')) {
            return 'This service is not available for the selected country. Please choose a different country.';
        }

        // Check if no providers are configured at all
        if (count($providers) === 0) {
            return 'No number providers are currently active. Please contact support.';
        }

        // Generic fallback
        return 'No numbers available for the selected country right now. Please try again later.';
    }

    /**
     * Release a provisioned number back to the provider.
     */
    public function releaseNumber(string $providerSid, ?int $providerId = null, ?string $providerSlug = null, ?string $providerOrderId = null): void
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
                '5sim'    => $this->release5SimNumber($provider, $providerOrderId ?? $providerSid),
                'smspool' => $this->releaseSmsPoolNumber($provider, $providerOrderId ?? $providerSid),
                'plivo'   => $this->releasePlivoNumber($provider, $providerSid),
                'vonage'  => $this->releaseVonageNumber($provider, $providerSid),
                'sms_activate' => $this->releaseSmsActivateNumber($provider, $providerOrderId ?? $providerSid),
                default   => Log::warning("No release handler for provider type: {$provider->type}"),
            };
        } catch (\Exception $e) {
            Log::warning("Failed to release number via {$provider->slug}: {$e->getMessage()}");
        }
    }

    /**
     * Check a 5sim order for SMS (polling).
     * Returns the order data from 5sim including any received SMS.
     */
    public function check5SimOrder(string $providerOrderId, ?int $providerId = null): ?array
    {
        $provider = $providerId
            ? ApiProvider::find($providerId)
            : ApiProvider::where('type', '5sim')->where('is_active', true)->first();

        if (!$provider) return null;

        try {
            $fiveSim = FiveSimService::fromProvider($provider);
            return $fiveSim->checkOrder((int) $providerOrderId);
        } catch (\Exception $e) {
            Log::warning("5SIM check order failed for {$providerOrderId}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Check an SMSPool order for SMS (polling).
     * Returns the order data from SMSPool including any received SMS.
     */
    public function checkSmsPoolOrder(string $providerOrderId, ?int $providerId = null): ?array
    {
        $provider = $providerId
            ? ApiProvider::find($providerId)
            : ApiProvider::where('type', 'smspool')->where('is_active', true)->first();

        if (!$provider) return null;

        try {
            $smsPool = SmsPoolService::fromProvider($provider);
            return $smsPool->checkSms($providerOrderId);
        } catch (\Exception $e) {
            Log::warning("SMSPool check order failed for {$providerOrderId}: {$e->getMessage()}");
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════
    //  INTERNAL POOL
    // ═══════════════════════════════════════════════════════

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
            'phone_number'      => $number->phone_number,
            'provider_sid'      => $number->provider_sid,
            'provider_order_id' => null,
            'provider_id'       => null,
            'provider_slug'     => 'internal',
            'response_ms'       => 0,
        ];
    }

    // ═══════════════════════════════════════════════════════
    //  ROUTING ORDER
    // ═══════════════════════════════════════════════════════

    private function getOrderedProviders()
    {
        $mode = ApiSetting::getValue('routing_mode', 'priority');

        $query = ApiProvider::where('is_active', true)
            ->whereNotNull('credentials');

        return match ($mode) {
            'cheapest' => $query->orderBy('cost_multiplier', 'asc')
                                ->orderBy('priority', 'asc')
                                ->get()
                                ->filter(fn($p) => $p->isConfigured()),

            'smart' => $query->get()
                             ->filter(fn($p) => $p->isConfigured())
                             ->sortByDesc(function ($p) {
                                 $sr = $p->success_rate ?: 50;
                                 $cm = $p->cost_multiplier ?: 1;
                                 $pr = $p->priority ?: 10;
                                 return ($sr * 0.6) + ((100 / $cm) * 0.2) + ((100 / $pr) * 0.2);
                             }),

            default => $query->orderBy('priority', 'asc')
                             ->orderBy('success_rate', 'desc')
                             ->get()
                             ->filter(fn($p) => $p->isConfigured()),
        };
    }

    // ═══════════════════════════════════════════════════════
    //  PROVIDER PROVISIONING
    // ═══════════════════════════════════════════════════════

    private function provisionFromProvider(ApiProvider $provider, Country $country, ?string $serviceSlug = null, string $operator = "any"): array
    {
        return match ($provider->type) {
            'twilio'  => $this->provisionTwilio($provider, $country),
            'telnyx'  => $this->provisionTelnyx($provider, $country),
            '5sim'    => $this->provision5Sim($provider, $country, $serviceSlug, $operator),
            'smspool' => $this->provisionSmsPool($provider, $country, $serviceSlug),
            'plivo'   => $this->provisionPlivo($provider, $country),
            'vonage'  => $this->provisionVonage($provider, $country),
            'smspva'  => $this->provisionSmsPva($provider, $country),
            'sms_activate' => $this->provisionSmsActivate($provider, $country, $serviceSlug, $operator),
            default   => throw new \Exception("Unsupported provider type: {$provider->type}"),
        };
    }

    // ═══════════════════════════════════════════════════════
    //  5SIM IMPLEMENTATION
    // ═══════════════════════════════════════════════════════

    /**
     * Buy an activation number via 5SIM.
     *
     * 5SIM uses country names (e.g. "england", "usa") and product names (e.g. "whatsapp", "telegram").
     * We map our ISO codes and service names to 5sim's format.
     */
    private function provision5Sim(ApiProvider $provider, Country $country, ?string $serviceSlug = null, string $operator = "any"): array
    {
        $fiveSim = FiveSimService::fromProvider($provider);

        // Map country code to 5sim country name
        $fiveSimCountry = FiveSimService::mapCountryCode($country->code);

        // Map service slug to 5sim product name
        $product = $serviceSlug
            ? FiveSimService::mapServiceToProduct($serviceSlug)
            : 'any';

        Log::info("5SIM: Buying activation for country={$fiveSimCountry}, product={$product}, operator={$operator}");

        // Buy the activation number
        $result = $fiveSim->buyActivationNumber($fiveSimCountry, $product, $operator);

        $phone = $result['phone'] ?? null;
        $orderId = $result['id'] ?? null;

        if (!$phone || !$orderId) {
            throw new \Exception('5SIM returned invalid response — no phone or order ID');
        }

        Log::info("5SIM: Number purchased — phone={$phone}, orderId={$orderId}, status={$result['status']}");

        return [
            'phone_number'      => $phone,
            'provider_sid'      => (string) $orderId, // Store 5sim order ID as provider_sid for compatibility
            'provider_order_id' => (string) $orderId,
        ];
    }

    /**
     * Cancel/finish a 5sim order (release number).
     *
     * 5sim status meanings:
     *   PENDING  → order created, number not yet assigned
     *   RECEIVED → number assigned, WAITING for SMS (NOT "SMS received")
     *   FINISHED → order completed (SMS was received and confirmed)
     *   CANCELED → order cancelled
     *   BANNED   → number was banned
     *   TIMEOUT  → order expired
     *
     * Only call finishOrder when actual SMS messages exist.
     * Otherwise cancel the order to get a refund on 5sim.
     */
    private function release5SimNumber(ApiProvider $provider, string $orderId): void
    {
        if (!$orderId || !is_numeric($orderId)) {
            Log::warning("5SIM: Cannot release — invalid order ID: {$orderId}");
            return;
        }

        try {
            $fiveSim = FiveSimService::fromProvider($provider);
            $order = $fiveSim->checkOrder((int) $orderId);
            $status = $order['status'] ?? '';
            $smsArray = $order['sms'] ?? [];

            if (in_array($status, ['FINISHED', 'CANCELED', 'BANNED', 'TIMEOUT'])) {
                Log::info("5SIM: Order {$orderId} already in terminal state: {$status}");
                return;
            }

            if (!empty($smsArray)) {
                // SMS was actually received — finish the order
                $fiveSim->finishOrder((int) $orderId);
                Log::info("5SIM: Finished order {$orderId} (SMS was received)");
            } else {
                // No SMS received — cancel the order (refund on 5sim side)
                $fiveSim->cancelOrder((int) $orderId);
                Log::info("5SIM: Cancelled order {$orderId} (no SMS received, status was: {$status})");
            }
        } catch (\Exception $e) {
            Log::warning("5SIM: Failed to release order {$orderId}: {$e->getMessage()}");
        }
    }

    // ═══════════════════════════════════════════════════════
    //  SMSPOOL IMPLEMENTATION
    // ═══════════════════════════════════════════════════════

    /**
     * Buy an SMS number via SMSPool.
     *
     * SMSPool uses numeric country IDs and service IDs.
     * We map our ISO codes and service slugs to SMSPool's format.
     */
    private function provisionSmsPool(ApiProvider $provider, Country $country, ?string $serviceSlug = null): array
    {
        $smsPool = SmsPoolService::fromProvider($provider);

        // Map country code to SMSPool country ID
        $smsPoolCountryId = SmsPoolService::mapCountryCode($country->code);
        if ($smsPoolCountryId === null) {
            throw new \Exception("SMSPool: Country {$country->code} is not supported");
        }

        // Map service slug to SMSPool service ID
        $smsPoolServiceId = $serviceSlug
            ? SmsPoolService::mapServiceToId($serviceSlug)
            : null;

        if ($smsPoolServiceId === null) {
            throw new \Exception("SMSPool: Service '{$serviceSlug}' is not mapped to an SMSPool service ID");
        }

        Log::info("SMSPool: Purchasing SMS for country_id={$smsPoolCountryId}, service_id={$smsPoolServiceId}");

        // Purchase the SMS number
        $result = $smsPool->purchaseSms($smsPoolCountryId, $smsPoolServiceId);

        $phone = $result['number'] ?? null;
        $orderId = $result['order_id'] ?? null;

        if (!$phone || !$orderId) {
            throw new \Exception('SMSPool returned invalid response — no phone number or order ID');
        }

        // Ensure phone number has + prefix
        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        Log::info("SMSPool: Number purchased — phone={$phone}, orderId={$orderId}");

        return [
            'phone_number'      => $phone,
            'provider_sid'      => (string) $orderId,
            'provider_order_id' => (string) $orderId,
        ];
    }

    /**
     * Cancel/release an SMSPool order.
     *
     * Checks the current status before cancelling.
     * Only cancels if the order is still pending (status 1) or processing (7/8).
     */
    private function releaseSmsPoolNumber(ApiProvider $provider, string $orderId): void
    {
        if (!$orderId) {
            Log::warning("SMSPool: Cannot release — invalid order ID: {$orderId}");
            return;
        }

        try {
            $smsPool = SmsPoolService::fromProvider($provider);

            // Check current status first
            $order = $smsPool->checkSms($orderId);
            $statusCode = (int) ($order['status'] ?? 0);
            $statusName = SmsPoolService::mapStatusCode($statusCode);

            // Already in a terminal state — nothing to do
            if (SmsPoolService::isTerminalStatus($statusCode)) {
                Log::info("SMSPool: Order {$orderId} already in terminal state: {$statusName}");
                return;
            }

            // Cancel the order
            $smsPool->cancelOrder($orderId);
            Log::info("SMSPool: Cancelled order {$orderId} (previous status: {$statusName})");

        } catch (\Exception $e) {
            Log::warning("SMSPool: Failed to release order {$orderId}: {$e->getMessage()}");
        }
    }

    // ═══════════════════════════════════════════════════════
    //  TWILIO IMPLEMENTATION
    // ═══════════════════════════════════════════════════════

    private function provisionTwilio(ApiProvider $provider, Country $country): array
    {
        $twilio = new TwilioClient(
            $provider->getCredential('account_sid'),
            $provider->getCredential('auth_token')
        );

        $countryCode = $country->twilio_code ?: $country->code;

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

        $webhookUrl = $provider->getSetting('webhook_url')
            ?: env('TWILIO_WEBHOOK_URL', rtrim(env('APP_URL'), '/') . '/api/webhook/sms');

        $createParams = ['phoneNumber' => $phoneNumber];

        if ($webhookUrl && !preg_match('/(localhost|127\.0\.0\.\d+)/i', $webhookUrl)) {
            $createParams['smsUrl']    = $webhookUrl;
            $createParams['smsMethod'] = 'POST';
        }

        $purchased = $twilio->incomingPhoneNumbers->create($createParams);

        return [
            'phone_number'      => $phoneNumber,
            'provider_sid'      => $purchased->sid,
            'provider_order_id' => null,
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

    // ═══════════════════════════════════════════════════════
    //  TELNYX IMPLEMENTATION
    // ═══════════════════════════════════════════════════════

    private function provisionTelnyx(ApiProvider $provider, Country $country): array
    {
        $apiKey = $provider->getCredential('api_key');
        if (!$apiKey) throw new \Exception('Telnyx API key not configured');

        $countryCode = $country->code;

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

        $phoneNumber = $available[0]['phone_number'];

        $orderPayload = [
            'phone_numbers' => [
                ['phone_number' => $phoneNumber],
            ],
        ];

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

        if ($messagingProfileId) {
            usleep(500000);
            $this->updateTelnyxNumberMessaging($apiKey, $phoneNumber, $messagingProfileId);
        }

        Log::info("Telnyx number provisioned: {$phoneNumber}, order ID: {$orderId}");

        return [
            'phone_number'      => $phoneNumber,
            'provider_sid'      => $orderId ?? $phoneNumber,
            'provider_order_id' => null,
        ];
    }

    private function updateTelnyxNumberMessaging(string $apiKey, string $phoneNumber, string $messagingProfileId): void
    {
        try {
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

    private function releaseTelnyxNumber(ApiProvider $provider, string $sid): void
    {
        $apiKey = $provider->getCredential('api_key');
        if (!$apiKey) {
            Log::warning("Cannot release Telnyx number — no API key for provider {$provider->slug}");
            return;
        }

        $phoneNumber = $sid;

        if (!str_starts_with($sid, '+')) {
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

    // ── SMSPVA ──
    private function provisionSmsPva(ApiProvider $provider, Country $country): array
    {
        $apiKey = $provider->getCredential('api_key');
        if (!$apiKey) throw new \Exception('SMSPVA API key not configured');
        throw new \Exception('SMSPVA provider integration coming soon');
    }

    // ── SMS-Activate (placeholder) ──
    private function provisionSmsActivate(ApiProvider $provider, Country $country, ?string $serviceSlug = null, string $operator = 'any'): array
    {
        $apiKey = $provider->getCredential('api_key');
        if (!$apiKey) throw new \Exception('SMS-Activate API key not configured');
        throw new \Exception('SMS-Activate provider integration coming soon');
    }

    private function releaseSmsActivateNumber(ApiProvider $provider, string $orderId): void
    {
        Log::info("SMS-Activate release placeholder for order {$orderId}");
    }

    // ═══════════════════════════════════════════════════════
    //  METRICS TRACKING
    // ═══════════════════════════════════════════════════════

    private function recordSuccess(ApiProvider $provider, int $responseMs): void
    {
        $total = $provider->total_requests + 1;
        $successes = $provider->total_successes + 1;

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
