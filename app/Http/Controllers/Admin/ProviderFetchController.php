<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiProvider;
use App\Models\ApiSetting;
use App\Models\Country;
use App\Models\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Twilio\Rest\Client as TwilioClient;

class ProviderFetchController extends Controller
{
    /* ── Pricing helpers ── */

    private function getExchangeRate(): float
    {
        return (float) ApiSetting::getValue('usd_to_ngn_rate', 1500);
    }

    private function getMarkupPercent(): float
    {
        return (float) ApiSetting::getValue('pricing_markup_percent', 0);
    }

    private function usdToNgn(float $usd): float
    {
        $rate = $this->getExchangeRate();
        $markup = $this->getMarkupPercent();
        $base = $usd * $rate;
        return round($base * (1 + ($markup / 100)), 2);
    }

    /**
     * Get all active providers from database.
     */
    public function providers()
    {
        $providers = ApiProvider::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($p) {
                return [
                    'id'            => $p->slug,
                    'provider_id'   => $p->id,
                    'name'          => $p->name,
                    'type'          => $p->type,
                    'is_configured' => $p->isConfigured(),
                    'can_fetch'     => $p->capabilities ?? ['countries', 'numbers', 'pricing'],
                    'description'   => $p->description,
                ];
            });

        return response()->json($providers);
    }

    /**
     * Resolve an ApiProvider by slug.
     */
    private function resolveProvider(string $providerSlug): ?ApiProvider
    {
        return ApiProvider::where('slug', $providerSlug)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Fetch supported countries from a provider (live API call).
     */
    public function fetchCountries(Request $request)
    {
        $request->validate(['provider' => 'required|string']);

        $provider = $this->resolveProvider($request->provider);
        if (!$provider) {
            return response()->json(['message' => "Provider not found or inactive."], 422);
        }

        if (!$provider->isConfigured()) {
            return response()->json(['message' => "Provider '{$provider->name}' credentials not configured."], 422);
        }

        if ($provider->type === 'twilio') {
            return $this->fetchTwilioCountries($provider);
        }

        if ($provider->type === 'telnyx') {
            return $this->fetchTelnyxCountries($provider);
        }

        return response()->json(['message' => "Provider type '{$provider->type}' not supported yet."], 422);
    }

    /**
     * Fetch available numbers from a provider for a given country.
     */
    public function fetchNumbers(Request $request)
    {
        $request->validate([
            'provider'     => 'required|string',
            'country_code' => 'required|string|size:2',
            'limit'        => 'nullable|integer|min:1|max:30',
        ]);

        $provider = $this->resolveProvider($request->provider);
        if (!$provider) {
            return response()->json(['message' => "Provider not found or inactive."], 422);
        }

        if (!$provider->isConfigured()) {
            return response()->json(['message' => "Provider '{$provider->name}' credentials not configured."], 422);
        }

        if ($provider->type === 'twilio') {
            return $this->fetchTwilioNumbers($provider, $request->country_code, $request->get('limit', 10));
        }

        if ($provider->type === 'telnyx') {
            return $this->fetchTelnyxNumbers($provider, $request->country_code, $request->get('limit', 10));
        }

        return response()->json(['message' => "Provider type '{$provider->type}' not supported yet."], 422);
    }

    /**
     * Fetch pricing info from a provider.
     */
    public function fetchPricing(Request $request)
    {
        $request->validate([
            'provider'     => 'required|string',
            'country_code' => 'nullable|string|size:2',
        ]);

        $provider = $this->resolveProvider($request->provider);
        if (!$provider) {
            return response()->json(['message' => "Provider not found or inactive."], 422);
        }

        if (!$provider->isConfigured()) {
            return response()->json(['message' => "Provider '{$provider->name}' credentials not configured."], 422);
        }

        if ($provider->type === 'twilio') {
            return $this->fetchTwilioPricing($provider, $request->country_code);
        }

        if ($provider->type === 'telnyx') {
            return $this->fetchTelnyxPricing($provider, $request->country_code);
        }

        return response()->json(['message' => "Provider type '{$provider->type}' not supported yet."], 422);
    }

    /**
     * Import fetched countries into the database.
     */
    public function importCountries(Request $request)
    {
        $request->validate([
            'countries'               => 'required|array|min:1',
            'countries.*.name'        => 'required|string',
            'countries.*.code'        => 'required|string|size:2',
            'countries.*.flag'        => 'nullable|string',
            'countries.*.dial_code'   => 'nullable|string',
            'countries.*.twilio_code' => 'nullable|string|max:2',
            'countries.*.price_usd'   => 'required|numeric|min:0',
        ]);

        $imported = 0;
        $skipped  = 0;

        foreach ($request->countries as $data) {
            if (Country::where('code', strtoupper($data['code']))->exists()) {
                $skipped++;
                continue;
            }

            Country::create([
                'name'        => $data['name'],
                'code'        => strtoupper($data['code']),
                'flag'        => $data['flag'] ?? '',
                'dial_code'   => $data['dial_code'] ?? '',
                'twilio_code' => strtoupper($data['twilio_code'] ?? $data['code']),
                'price_usd'   => $data['price_usd'],
                'price'       => $this->usdToNgn((float) $data['price_usd']),
                'is_active'   => true,
            ]);
            $imported++;
        }

        return response()->json([
            'message'  => "Imported {$imported} countries. Skipped {$skipped} duplicates.",
            'imported' => $imported,
            'skipped'  => $skipped,
        ]);
    }

    /**
     * Import fetched numbers into the phone_numbers inventory.
     */
    public function importNumbers(Request $request)
    {
        $request->validate([
            'numbers'                => 'required|array|min:1',
            'numbers.*.phone_number' => 'required|string',
            'numbers.*.country_code' => 'required|string|size:2',
            'numbers.*.provider'     => 'required|string',
            'numbers.*.provider_sid' => 'nullable|string',
            'numbers.*.cost_price'   => 'nullable|numeric|min:0',
            'sell_price'             => 'required|numeric|min:0',
        ]);

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($request->numbers as $i => $row) {
            $country = Country::where('code', strtoupper($row['country_code']))->first();
            if (!$country) {
                $errors[] = "Row " . ($i + 1) . ": Country {$row['country_code']} not found in database.";
                $skipped++;
                continue;
            }

            if (PhoneNumber::where('phone_number', $row['phone_number'])->exists()) {
                $skipped++;
                continue;
            }

            PhoneNumber::create([
                'phone_number' => $row['phone_number'],
                'country_id'   => $country->id,
                'provider'     => $row['provider'],
                'provider_sid' => $row['provider_sid'] ?? null,
                'status'       => 'available',
                'cost_price'   => $row['cost_price'] ?? 0,
                'sell_price'   => $request->sell_price,
                'max_uses'     => $request->get('max_uses', 1),
            ]);
            $imported++;
        }

        return response()->json([
            'message'  => "Imported {$imported} numbers to inventory. Skipped {$skipped}.",
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
    }

    /* ══════════════════════════════════════════════
       TWILIO IMPLEMENTATION
       ══════════════════════════════════════════════ */

    private function getTwilioClient(ApiProvider $provider): TwilioClient
    {
        $sid   = $provider->getCredential('account_sid');
        $token = $provider->getCredential('auth_token');

        if (!$sid || !$token) {
            throw new \Exception('Twilio credentials not configured. Go to API Settings → Providers first.');
        }

        return new TwilioClient($sid, $token);
    }

    private function fetchTwilioCountries(ApiProvider $provider)
    {
        try {
            $twilio = $this->getTwilioClient($provider);
            $countries = $twilio->availablePhoneNumbers->read();

            $results = [];
            $flagMap = $this->getCountryFlagMap();
            $dialCodeMap = $this->getDialCodeMap();
            $existingCodes = Country::pluck('code')->map(fn($c) => strtoupper($c))->toArray();
            $rate = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            foreach ($countries as $country) {
                $code = strtoupper($country->countryCode);
                $priceUsd = $this->estimateTwilioPrice($code);
                $priceNgn = $this->usdToNgn($priceUsd);

                $results[] = [
                    'name'           => $country->country,
                    'code'           => $code,
                    'flag'           => $flagMap[$code] ?? '',
                    'dial_code'      => $dialCodeMap[$code] ?? '',
                    'twilio_code'    => $code,
                    'price_usd'      => $priceUsd,
                    'price_ngn'      => $priceNgn,
                    'already_exists' => in_array($code, $existingCodes),
                ];
            }

            usort($results, function ($a, $b) {
                if ($a['already_exists'] !== $b['already_exists']) {
                    return $a['already_exists'] ? 1 : -1;
                }
                return strcmp($a['name'], $b['name']);
            });

            return response()->json([
                'provider'       => $provider->slug,
                'total'          => count($results),
                'new_count'      => count(array_filter($results, fn($r) => !$r['already_exists'])),
                'countries'      => $results,
                'exchange_rate'  => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('Twilio fetchCountries error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch from Twilio: ' . $e->getMessage(),
            ], 502);
        }
    }

    private function fetchTwilioNumbers(ApiProvider $provider, string $countryCode, int $limit = 10)
    {
        try {
            $twilio = $this->getTwilioClient($provider);
            $countryCode = strtoupper($countryCode);

            $numbers = [];
            $types = ['local', 'mobile', 'tollFree'];

            foreach ($types as $type) {
                try {
                    $available = $twilio->availablePhoneNumbers($countryCode)
                        ->{$type}
                        ->read(['smsEnabled' => true], $limit - count($numbers));

                    foreach ($available as $num) {
                        $numbers[] = [
                            'phone_number'  => $num->phoneNumber,
                            'friendly_name' => $num->friendlyName,
                            'country_code'  => $countryCode,
                            'type'          => $type,
                            'capabilities'  => [
                                'sms'   => $num->capabilities->sms ?? false,
                                'mms'   => $num->capabilities->mms ?? false,
                                'voice' => $num->capabilities->voice ?? false,
                            ],
                            'provider'      => $provider->slug,
                            'region'        => $num->region ?? null,
                            'locality'      => $num->locality ?? null,
                        ];
                    }
                } catch (\Exception $e) {
                    continue;
                }

                if (count($numbers) >= $limit) break;
            }

            $existingNumbers = PhoneNumber::whereIn('phone_number', array_column($numbers, 'phone_number'))
                ->pluck('phone_number')->toArray();

            foreach ($numbers as &$num) {
                $num['already_in_inventory'] = in_array($num['phone_number'], $existingNumbers);
            }

            return response()->json([
                'provider'       => $provider->slug,
                'country_code'   => $countryCode,
                'total'          => count($numbers),
                'numbers'        => $numbers,
                'exchange_rate'  => $this->getExchangeRate(),
                'markup_percent' => $this->getMarkupPercent(),
            ]);

        } catch (\Exception $e) {
            Log::error("Twilio fetchNumbers error ({$countryCode}): " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch numbers: ' . $e->getMessage(),
            ], 502);
        }
    }

    private function fetchTwilioPricing(ApiProvider $provider, ?string $countryCode = null)
    {
        try {
            $twilio = $this->getTwilioClient($provider);
            $rate = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            if ($countryCode) {
                $pricing = $twilio->pricing->v1->phoneNumbers
                    ->countries(strtoupper($countryCode))
                    ->fetch();

                $prices = [];
                if (!empty($pricing->phoneNumberPrices)) {
                    foreach ($pricing->phoneNumberPrices as $p) {
                        $baseUsd    = (float) ($p['base_price'] ?? 0);
                        $currentUsd = (float) ($p['current_price'] ?? 0);
                        $prices[] = [
                            'number_type'      => $p['number_type'] ?? 'unknown',
                            'base_price_usd'   => $baseUsd,
                            'current_price_usd'=> $currentUsd,
                            'base_price_ngn'   => $this->usdToNgn($baseUsd),
                            'current_price_ngn'=> $this->usdToNgn($currentUsd),
                        ];
                    }
                }

                return response()->json([
                    'provider'       => $provider->slug,
                    'country'        => $pricing->country,
                    'country_code'   => $pricing->isoCountry,
                    'prices'         => $prices,
                    'exchange_rate'  => $rate,
                    'markup_percent' => $markup,
                ]);
            }

            $pricingList = $twilio->pricing->v1->phoneNumbers->countries->read([], 50);
            $results = [];
            foreach ($pricingList as $item) {
                $results[] = [
                    'country'      => $item->country,
                    'country_code' => $item->isoCountry,
                ];
            }

            return response()->json([
                'provider'       => $provider->slug,
                'countries'      => $results,
                'exchange_rate'  => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('Twilio fetchPricing error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch pricing: ' . $e->getMessage(),
            ], 502);
        }
    }

/* ══════════════════════════════════════
       TELNYX IMPLEMENTATION
       ══════════════════════════════════════ */

    private function getTelnyxHeaders(ApiProvider $provider): array
    {
        $apiKey = $provider->getCredential('api_key');
        if (!$apiKey) {
            throw new \Exception('Telnyx API key not configured. Go to API Settings → Providers first.');
        }

        return [
            'Authorization' => "Bearer {$apiKey}",
            'Accept'        => 'application/json',
        ];
    }

    private function fetchTelnyxCountries(ApiProvider $provider)
    {
        try {
            $headers = $this->getTelnyxHeaders($provider);

            // Telnyx doesn't have a direct "list countries" endpoint.
            // We search available numbers per known country codes to find which are supported.
            // For efficiency, we search a curated list of popular countries.
            $countryCodes = [
                'US', 'CA', 'GB', 'AU', 'DE', 'FR', 'ES', 'IT', 'NL', 'SE',
                'NO', 'DK', 'FI', 'AT', 'BE', 'CH', 'IE', 'PT', 'PL', 'CZ',
                'IL', 'HK', 'SG', 'NZ', 'ZA', 'MX', 'BR', 'CL', 'CO', 'PE',
                'RO', 'BG', 'HR', 'SK', 'HU', 'GR', 'EE', 'LT', 'LV', 'PR',
                'JP', 'KR', 'IN', 'PH', 'ID', 'TR', 'NG', 'KE', 'GH',
            ];

            $flagMap     = $this->getCountryFlagMap();
            $dialCodeMap = $this->getDialCodeMap();
            $existingCodes = Country::pluck('code')->map(fn($c) => strtoupper($c))->toArray();
            $rate   = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            $results = [];
            foreach ($countryCodes as $code) {
                try {
                    $response = Http::withHeaders($headers)->get('https://api.telnyx.com/v2/available_phone_numbers', [
                        'filter[country_code]' => $code,
                        'filter[limit]'        => 1,
                    ]);

                    if ($response->successful() && !empty($response->json('data', []))) {
                        $priceUsd = $this->estimateTelnyxPrice($code);
                        $results[] = [
                            'name'           => $this->getCountryName($code),
                            'code'           => $code,
                            'flag'           => $flagMap[$code] ?? '',
                            'dial_code'      => $dialCodeMap[$code] ?? '',
                            'twilio_code'    => $code,
                            'price_usd'      => $priceUsd,
                            'price_ngn'      => $this->usdToNgn($priceUsd),
                            'already_exists' => in_array($code, $existingCodes),
                        ];
                    }
                } catch (\Exception $e) {
                    continue; // Skip countries that error out
                }

                // Rate limiting — Telnyx has limits
                usleep(100000); // 100ms between requests
            }

            usort($results, function ($a, $b) {
                if ($a['already_exists'] !== $b['already_exists']) {
                    return $a['already_exists'] ? 1 : -1;
                }
                return strcmp($a['name'], $b['name']);
            });

            return response()->json([
                'provider'       => $provider->slug,
                'total'          => count($results),
                'new_count'      => count(array_filter($results, fn($r) => !$r['already_exists'])),
                'countries'      => $results,
                'exchange_rate'  => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('Telnyx fetchCountries error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch from Telnyx: ' . $e->getMessage(),
            ], 502);
        }
    }

    private function fetchTelnyxNumbers(ApiProvider $provider, string $countryCode, int $limit = 10)
    {
        try {
            $headers = $this->getTelnyxHeaders($provider);
            $countryCode = strtoupper($countryCode);

            $response = Http::withHeaders($headers)->get('https://api.telnyx.com/v2/available_phone_numbers', [
                'filter[country_code]' => $countryCode,
                'filter[features]'     => 'sms',
                'filter[limit]'        => $limit,
            ]);

            if (!$response->successful()) {
                $error = $response->json('errors.0.detail') ?? $response->body();
                throw new \Exception("Telnyx API error: {$error}");
            }

            $data = $response->json('data', []);
            $numbers = [];

            foreach ($data as $num) {
                $features = $num['features'] ?? [];
                $numbers[] = [
                    'phone_number'  => $num['phone_number'],
                    'friendly_name' => $num['phone_number'],
                    'country_code'  => $countryCode,
                    'type'          => $num['phone_number_type'] ?? 'local',
                    'capabilities'  => [
                        'sms'   => in_array('sms', $features),
                        'mms'   => in_array('mms', $features),
                        'voice' => in_array('voice', $features),
                    ],
                    'provider'      => $provider->slug,
                    'region'        => $num['region_information'][0]['region_name'] ?? null,
                    'locality'      => $num['region_information'][0]['region_type'] ?? null,
                    'cost_usd'      => $num['cost_information']['upfront_cost'] ?? null,
                    'monthly_cost'  => $num['cost_information']['monthly_cost'] ?? null,
                ];
            }

            $existingNumbers = PhoneNumber::whereIn('phone_number', array_column($numbers, 'phone_number'))
                ->pluck('phone_number')->toArray();

            foreach ($numbers as &$num) {
                $num['already_in_inventory'] = in_array($num['phone_number'], $existingNumbers);
            }

            return response()->json([
                'provider'       => $provider->slug,
                'country_code'   => $countryCode,
                'total'          => count($numbers),
                'numbers'        => $numbers,
                'exchange_rate'  => $this->getExchangeRate(),
                'markup_percent' => $this->getMarkupPercent(),
            ]);

        } catch (\Exception $e) {
            Log::error("Telnyx fetchNumbers error ({$countryCode}): " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch numbers from Telnyx: ' . $e->getMessage(),
            ], 502);
        }
    }

    private function fetchTelnyxPricing(ApiProvider $provider, ?string $countryCode = null)
    {
        try {
            $rate   = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            if ($countryCode) {
                // Fetch pricing for a specific country by searching for numbers
                $headers = $this->getTelnyxHeaders($provider);
                $response = Http::withHeaders($headers)->get('https://api.telnyx.com/v2/available_phone_numbers', [
                    'filter[country_code]' => strtoupper($countryCode),
                    'filter[limit]'        => 3,
                ]);

                $prices = [];
                if ($response->successful()) {
                    $data = $response->json('data', []);
                    $seenTypes = [];
                    foreach ($data as $num) {
                        $type = $num['phone_number_type'] ?? 'local';
                        if (in_array($type, $seenTypes)) continue;
                        $seenTypes[] = $type;

                        $costInfo = $num['cost_information'] ?? [];
                        $monthly  = (float) ($costInfo['monthly_cost'] ?? 1.00);
                        $upfront  = (float) ($costInfo['upfront_cost'] ?? 0);

                        $prices[] = [
                            'number_type'       => $type,
                            'base_price_usd'    => $monthly,
                            'current_price_usd' => $monthly,
                            'base_price_ngn'    => $this->usdToNgn($monthly),
                            'current_price_ngn' => $this->usdToNgn($monthly),
                            'upfront_usd'       => $upfront,
                            'upfront_ngn'       => $this->usdToNgn($upfront),
                        ];
                    }
                }

                return response()->json([
                    'provider'       => $provider->slug,
                    'country'        => $this->getCountryName(strtoupper($countryCode)),
                    'country_code'   => strtoupper($countryCode),
                    'prices'         => $prices,
                    'exchange_rate'  => $rate,
                    'markup_percent' => $markup,
                ]);
            }

            // No specific country — return general pricing overview
            $results = [];
            $popularCountries = ['US', 'CA', 'GB', 'DE', 'FR', 'AU', 'NL', 'SE', 'IE', 'NZ'];

            foreach ($popularCountries as $code) {
                $results[] = [
                    'country'      => $this->getCountryName($code),
                    'country_code' => $code,
                ];
            }

            return response()->json([
                'provider'       => $provider->slug,
                'countries'      => $results,
                'exchange_rate'  => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('Telnyx fetchPricing error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch pricing from Telnyx: ' . $e->getMessage(),
            ], 502);
        }
    }

    private function estimateTelnyxPrice(string $code): float
    {
        // Telnyx monthly number pricing estimates (USD)
        $prices = [
            'US' => 1.00, 'CA' => 1.00, 'GB' => 1.50, 'AU' => 3.00, 'DE' => 1.50,
            'FR' => 1.50, 'ES' => 1.50, 'IT' => 6.00, 'NL' => 1.50, 'SE' => 1.00,
            'NO' => 1.50, 'DK' => 1.50, 'FI' => 3.00, 'AT' => 1.50, 'BE' => 3.00,
            'CH' => 3.00, 'IE' => 1.50, 'PT' => 6.00, 'PL' => 1.00, 'CZ' => 1.00,
            'JP' => 4.50, 'KR' => 2.00, 'IN' => 0.50, 'BR' => 2.00, 'MX' => 2.00,
            'NG' => 6.00, 'ZA' => 1.00, 'IL' => 3.00, 'HK' => 3.00, 'SG' => 4.00,
            'PH' => 3.00, 'ID' => 3.00, 'CL' => 2.00, 'CO' => 2.00, 'NZ' => 3.00,
            'EE' => 1.00, 'LT' => 1.00, 'LV' => 1.00, 'RO' => 1.00, 'BG' => 1.00,
            'HR' => 1.00, 'SK' => 1.00, 'HU' => 1.00, 'GR' => 1.00, 'PR' => 1.00,
            'TR' => 2.00, 'KE' => 3.00, 'GH' => 3.00,
        ];
        return $prices[$code] ?? 1.50;
    }

    private function getCountryName(string $code): string
    {
        $names = [
            'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom',
            'AU' => 'Australia', 'DE' => 'Germany', 'FR' => 'France',
            'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands',
            'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark',
            'FI' => 'Finland', 'AT' => 'Austria', 'BE' => 'Belgium',
            'CH' => 'Switzerland', 'IE' => 'Ireland', 'PT' => 'Portugal',
            'PL' => 'Poland', 'CZ' => 'Czech Republic', 'JP' => 'Japan',
            'KR' => 'South Korea', 'IN' => 'India', 'BR' => 'Brazil',
            'MX' => 'Mexico', 'NG' => 'Nigeria', 'ZA' => 'South Africa',
            'IL' => 'Israel', 'HK' => 'Hong Kong', 'SG' => 'Singapore',
            'PH' => 'Philippines', 'ID' => 'Indonesia', 'CL' => 'Chile',
            'CO' => 'Colombia', 'NZ' => 'New Zealand', 'TR' => 'Turkey',
            'KE' => 'Kenya', 'GH' => 'Ghana', 'PE' => 'Peru',
            'EE' => 'Estonia', 'LT' => 'Lithuania', 'LV' => 'Latvia',
            'RO' => 'Romania', 'BG' => 'Bulgaria', 'HR' => 'Croatia',
            'SK' => 'Slovakia', 'HU' => 'Hungary', 'GR' => 'Greece',
            'PR' => 'Puerto Rico', 'AR' => 'Argentina',
        ];
        return $names[$code] ?? $code;
    }

    /* ── Helper Maps ── */

    private function estimateTwilioPrice(string $code): float
    {
        $prices = [
            'US' => 1.00, 'CA' => 1.00, 'GB' => 1.00, 'AU' => 2.00, 'DE' => 1.00,
            'FR' => 1.00, 'ES' => 1.00, 'IT' => 1.00, 'NL' => 1.00, 'SE' => 1.00,
            'NO' => 1.00, 'DK' => 1.00, 'FI' => 1.00, 'AT' => 1.00, 'BE' => 1.00,
            'CH' => 1.00, 'IE' => 1.00, 'PT' => 1.00, 'PL' => 1.00, 'CZ' => 1.00,
            'JP' => 4.50, 'KR' => 2.00, 'IN' => 0.50, 'BR' => 2.00, 'MX' => 2.00,
            'NG' => 6.00, 'ZA' => 1.00, 'IL' => 3.00, 'HK' => 3.00, 'SG' => 4.00,
            'PH' => 3.00, 'ID' => 3.00, 'CL' => 2.00, 'CO' => 2.00, 'AR' => 5.00,
            'EE' => 1.00, 'LT' => 1.00, 'LV' => 1.00, 'RO' => 1.00, 'BG' => 1.00,
            'HR' => 1.00, 'SK' => 1.00, 'HU' => 1.00, 'GR' => 1.00, 'PR' => 1.00,
        ];
        return $prices[$code] ?? 1.50;
    }

    private function getCountryFlagMap(): array
    {
        $map = [];
        foreach (range('A', 'Z') as $l1) {
            foreach (range('A', 'Z') as $l2) {
                $code = $l1 . $l2;
                $flag = mb_chr(0x1F1E6 + ord($l1) - ord('A')) . mb_chr(0x1F1E6 + ord($l2) - ord('A'));
                $map[$code] = $flag;
            }
        }
        return $map;
    }

    private function getDialCodeMap(): array
    {
        return [
            'US' => '+1', 'CA' => '+1', 'GB' => '+44', 'AU' => '+61', 'DE' => '+49',
            'FR' => '+33', 'ES' => '+34', 'IT' => '+39', 'NL' => '+31', 'SE' => '+46',
            'NO' => '+47', 'DK' => '+45', 'FI' => '+358', 'AT' => '+43', 'BE' => '+32',
            'CH' => '+41', 'IE' => '+353', 'PT' => '+351', 'PL' => '+48', 'CZ' => '+420',
            'JP' => '+81', 'KR' => '+82', 'IN' => '+91', 'BR' => '+55', 'MX' => '+52',
            'NG' => '+234', 'ZA' => '+27', 'IL' => '+972', 'HK' => '+852', 'SG' => '+65',
            'PH' => '+63', 'ID' => '+62', 'CL' => '+56', 'CO' => '+57', 'AR' => '+54',
            'EE' => '+372', 'LT' => '+370', 'LV' => '+371', 'RO' => '+40', 'BG' => '+359',
            'HR' => '+385', 'SK' => '+421', 'HU' => '+36', 'GR' => '+30', 'PR' => '+1',
            'NZ' => '+64', 'MY' => '+60', 'TH' => '+66', 'VN' => '+84', 'PK' => '+92',
            'BD' => '+880', 'LK' => '+94', 'KE' => '+254', 'GH' => '+233', 'TZ' => '+255',
            'UG' => '+256', 'EG' => '+20', 'MA' => '+212', 'TN' => '+216', 'DZ' => '+213',
            'SA' => '+966', 'AE' => '+971', 'QA' => '+974', 'KW' => '+965', 'BH' => '+973',
            'OM' => '+968', 'JO' => '+962', 'LB' => '+961', 'TR' => '+90', 'UA' => '+380',
            'RU' => '+7', 'BY' => '+375', 'GE' => '+995', 'AM' => '+374', 'AZ' => '+994',
            'KZ' => '+7', 'UZ' => '+998', 'TW' => '+886', 'PE' => '+51', 'EC' => '+593',
            'VE' => '+58', 'BO' => '+591', 'PY' => '+595', 'UY' => '+598', 'DO' => '+1',
            'GT' => '+502', 'HN' => '+504', 'SV' => '+503', 'NI' => '+505', 'CR' => '+506',
            'PA' => '+507', 'JM' => '+1', 'TT' => '+1', 'CY' => '+357', 'LU' => '+352',
            'MT' => '+356', 'IS' => '+354', 'SI' => '+386', 'RS' => '+381', 'BA' => '+387',
            'MK' => '+389', 'AL' => '+355', 'ME' => '+382', 'MD' => '+373', 'XK' => '+383',
        ];
    }
}
