<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiProvider;
use App\Models\ApiSetting;
use App\Models\Country;
use App\Models\PhoneNumber;
use App\Models\Service;
use App\Services\FiveSimService;
use App\Services\SmsPoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
                    'id' => $p->slug,
                    'provider_id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->type,
                    'is_configured' => $p->isConfigured(),
                    'can_fetch' => $p->capabilities ?? ['countries', 'numbers', 'pricing'],
                    'description' => $p->description,
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
        if (! $provider) {
            return response()->json(['message' => 'Provider not found or inactive.'], 422);
        }

        if (! $provider->isConfigured()) {
            return response()->json(['message' => "Provider '{$provider->name}' credentials not configured."], 422);
        }

        if ($provider->type === 'twilio') {
            return $this->fetchTwilioCountries($provider);
        }

        if ($provider->type === 'telnyx') {
            return $this->fetchTelnyxCountries($provider);
        }

        if ($provider->type === '5sim') {
            return $this->fetch5SimCountries($provider);
        }

        if ($provider->type === 'smspool') {
            return $this->fetchSmsPoolCountries($provider);
        }

        return response()->json(['message' => "Provider type '{$provider->type}' not supported yet."], 422);
    }

    /**
     * Fetch available numbers from a provider for a given country.
     */
    public function fetchNumbers(Request $request)
    {
        $request->validate([
            'provider' => 'required|string',
            'country_code' => 'required|string|size:2',
            'limit' => 'nullable|integer|min:1|max:30',
        ]);

        $provider = $this->resolveProvider($request->provider);
        if (! $provider) {
            return response()->json(['message' => 'Provider not found or inactive.'], 422);
        }

        if (! $provider->isConfigured()) {
            return response()->json(['message' => "Provider '{$provider->name}' credentials not configured."], 422);
        }

        if ($provider->type === 'twilio') {
            return $this->fetchTwilioNumbers($provider, $request->country_code, $request->get('limit', 10));
        }

        if ($provider->type === 'telnyx') {
            return $this->fetchTelnyxNumbers($provider, $request->country_code, $request->get('limit', 10));
        }

        if ($provider->type === '5sim') {
            return $this->fetch5SimProducts($provider, $request->country_code);
        }

        if ($provider->type === 'smspool') {
            return $this->fetchSmsPoolNumbers($provider, $request->country_code);
        }

        return response()->json(['message' => "Provider type '{$provider->type}' not supported yet."], 422);
    }

    /**
     * Fetch pricing info from a provider.
     */
    public function fetchPricing(Request $request)
    {
        $request->validate([
            'provider' => 'required|string',
            'country_code' => 'nullable|string|size:2',
        ]);

        $provider = $this->resolveProvider($request->provider);
        if (! $provider) {
            return response()->json(['message' => 'Provider not found or inactive.'], 422);
        }

        if (! $provider->isConfigured()) {
            return response()->json(['message' => "Provider '{$provider->name}' credentials not configured."], 422);
        }

        if ($provider->type === 'twilio') {
            return $this->fetchTwilioPricing($provider, $request->country_code);
        }

        if ($provider->type === 'telnyx') {
            return $this->fetchTelnyxPricing($provider, $request->country_code);
        }

        if ($provider->type === '5sim') {
            return $this->fetch5SimPricing($provider, $request->country_code);
        }

        if ($provider->type === 'smspool') {
            return $this->fetchSmsPoolPricing($provider, $request->country_code);
        }

        return response()->json(['message' => "Provider type '{$provider->type}' not supported yet."], 422);
    }

    /**
     * Import fetched countries into the database.
     */
    public function importCountries(Request $request)
    {
        $request->validate([
            'countries' => 'required|array|min:1',
            'countries.*.name' => 'required|string',
            'countries.*.code' => 'required|string|size:2',
            'countries.*.flag' => 'nullable|string',
            'countries.*.dial_code' => 'nullable|string',
            'countries.*.twilio_code' => 'nullable|string|max:2',
            'countries.*.price_usd' => 'required|numeric|min:0',
        ]);

        $imported = 0;
        $skipped = 0;

        foreach ($request->countries as $data) {
            if (Country::where('code', strtoupper($data['code']))->exists()) {
                $skipped++;

                continue;
            }

            Country::create([
                'name' => $data['name'],
                'code' => strtoupper($data['code']),
                'flag' => $data['flag'] ?? '',
                'dial_code' => $data['dial_code'] ?? '',
                'twilio_code' => strtoupper($data['twilio_code'] ?? $data['code']),
                'price_usd' => $data['price_usd'],
                'price' => $this->usdToNgn((float) $data['price_usd']),
                'is_active' => true,
            ]);
            $imported++;
        }

        return response()->json([
            'message' => "Imported {$imported} countries. Skipped {$skipped} duplicates.",
            'imported' => $imported,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Import fetched numbers into the phone_numbers inventory.
     */
    public function importNumbers(Request $request)
    {
        $request->validate([
            'numbers' => 'required|array|min:1',
            'numbers.*.phone_number' => 'required|string',
            'numbers.*.country_code' => 'required|string|size:2',
            'numbers.*.provider' => 'required|string',
            'numbers.*.provider_sid' => 'nullable|string',
            'numbers.*.cost_price' => 'nullable|numeric|min:0',
            'sell_price' => 'required|numeric|min:0',
        ]);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($request->numbers as $i => $row) {
            $country = Country::where('code', strtoupper($row['country_code']))->first();
            if (! $country) {
                $errors[] = 'Row '.($i + 1).": Country {$row['country_code']} not found in database.";
                $skipped++;

                continue;
            }

            if (PhoneNumber::where('phone_number', $row['phone_number'])->exists()) {
                $skipped++;

                continue;
            }

            PhoneNumber::create([
                'phone_number' => $row['phone_number'],
                'country_id' => $country->id,
                'provider' => $row['provider'],
                'provider_sid' => $row['provider_sid'] ?? null,
                'status' => 'available',
                'cost_price' => $row['cost_price'] ?? 0,
                'sell_price' => $request->sell_price,
                'max_uses' => $request->get('max_uses', 1),
            ]);
            $imported++;
        }

        return response()->json([
            'message' => "Imported {$imported} numbers to inventory. Skipped {$skipped}.",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    /* ══════════════════════════════════════════════
       TWILIO IMPLEMENTATION
       ══════════════════════════════════════════════ */

    private function getTwilioClient(ApiProvider $provider): TwilioClient
    {
        $sid = $provider->getCredential('account_sid');
        $token = $provider->getCredential('auth_token');

        if (! $sid || ! $token) {
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
            $existingCodes = Country::pluck('code')->map(fn ($c) => strtoupper($c))->toArray();
            $rate = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            foreach ($countries as $country) {
                $code = strtoupper($country->countryCode);
                $priceUsd = $this->estimateTwilioPrice($code);
                $priceNgn = $this->usdToNgn($priceUsd);

                $results[] = [
                    'name' => $country->country,
                    'code' => $code,
                    'flag' => $flagMap[$code] ?? '',
                    'dial_code' => $dialCodeMap[$code] ?? '',
                    'twilio_code' => $code,
                    'price_usd' => $priceUsd,
                    'price_ngn' => $priceNgn,
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
                'provider' => $provider->slug,
                'total' => count($results),
                'new_count' => count(array_filter($results, fn ($r) => ! $r['already_exists'])),
                'countries' => $results,
                'exchange_rate' => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('Twilio fetchCountries error: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch from Twilio: '.$e->getMessage(),
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
                            'phone_number' => $num->phoneNumber,
                            'friendly_name' => $num->friendlyName,
                            'country_code' => $countryCode,
                            'type' => $type,
                            'capabilities' => [
                                'sms' => $num->capabilities->sms ?? false,
                                'mms' => $num->capabilities->mms ?? false,
                                'voice' => $num->capabilities->voice ?? false,
                            ],
                            'provider' => $provider->slug,
                            'region' => $num->region ?? null,
                            'locality' => $num->locality ?? null,
                        ];
                    }
                } catch (\Exception $e) {
                    continue;
                }

                if (count($numbers) >= $limit) {
                    break;
                }
            }

            $existingNumbers = PhoneNumber::whereIn('phone_number', array_column($numbers, 'phone_number'))
                ->pluck('phone_number')->toArray();

            foreach ($numbers as &$num) {
                $num['already_in_inventory'] = in_array($num['phone_number'], $existingNumbers);
            }

            return response()->json([
                'provider' => $provider->slug,
                'country_code' => $countryCode,
                'total' => count($numbers),
                'numbers' => $numbers,
                'exchange_rate' => $this->getExchangeRate(),
                'markup_percent' => $this->getMarkupPercent(),
            ]);

        } catch (\Exception $e) {
            Log::error("Twilio fetchNumbers error ({$countryCode}): ".$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch numbers: '.$e->getMessage(),
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
                if (! empty($pricing->phoneNumberPrices)) {
                    foreach ($pricing->phoneNumberPrices as $p) {
                        $baseUsd = (float) ($p['base_price'] ?? 0);
                        $currentUsd = (float) ($p['current_price'] ?? 0);
                        $prices[] = [
                            'number_type' => $p['number_type'] ?? 'unknown',
                            'base_price_usd' => $baseUsd,
                            'current_price_usd' => $currentUsd,
                            'base_price_ngn' => $this->usdToNgn($baseUsd),
                            'current_price_ngn' => $this->usdToNgn($currentUsd),
                        ];
                    }
                }

                return response()->json([
                    'provider' => $provider->slug,
                    'country' => $pricing->country,
                    'country_code' => $pricing->isoCountry,
                    'prices' => $prices,
                    'exchange_rate' => $rate,
                    'markup_percent' => $markup,
                ]);
            }

            $pricingList = $twilio->pricing->v1->phoneNumbers->countries->read([], 50);
            $results = [];
            foreach ($pricingList as $item) {
                $results[] = [
                    'country' => $item->country,
                    'country_code' => $item->isoCountry,
                ];
            }

            return response()->json([
                'provider' => $provider->slug,
                'countries' => $results,
                'exchange_rate' => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('Twilio fetchPricing error: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch pricing: '.$e->getMessage(),
            ], 502);
        }
    }

    /* ══════════════════════════════════════
           TELNYX IMPLEMENTATION
           ══════════════════════════════════════ */

    private function getTelnyxHeaders(ApiProvider $provider): array
    {
        $apiKey = $provider->getCredential('api_key');
        if (! $apiKey) {
            throw new \Exception('Telnyx API key not configured. Go to API Settings → Providers first.');
        }

        return [
            'Authorization' => "Bearer {$apiKey}",
            'Accept' => 'application/json',
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

            $flagMap = $this->getCountryFlagMap();
            $dialCodeMap = $this->getDialCodeMap();
            $existingCodes = Country::pluck('code')->map(fn ($c) => strtoupper($c))->toArray();
            $rate = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            $results = [];
            foreach ($countryCodes as $code) {
                try {
                    $response = Http::withHeaders($headers)->get('https://api.telnyx.com/v2/available_phone_numbers', [
                        'filter[country_code]' => $code,
                        'filter[limit]' => 1,
                    ]);

                    if ($response->successful() && ! empty($response->json('data', []))) {
                        $priceUsd = $this->estimateTelnyxPrice($code);
                        $results[] = [
                            'name' => $this->getCountryName($code),
                            'code' => $code,
                            'flag' => $flagMap[$code] ?? '',
                            'dial_code' => $dialCodeMap[$code] ?? '',
                            'twilio_code' => $code,
                            'price_usd' => $priceUsd,
                            'price_ngn' => $this->usdToNgn($priceUsd),
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
                'provider' => $provider->slug,
                'total' => count($results),
                'new_count' => count(array_filter($results, fn ($r) => ! $r['already_exists'])),
                'countries' => $results,
                'exchange_rate' => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('Telnyx fetchCountries error: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch from Telnyx: '.$e->getMessage(),
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
                'filter[features]' => 'sms',
                'filter[limit]' => $limit,
            ]);

            if (! $response->successful()) {
                $error = $response->json('errors.0.detail') ?? $response->body();
                throw new \Exception("Telnyx API error: {$error}");
            }

            $data = $response->json('data', []);
            $numbers = [];

            foreach ($data as $num) {
                $features = $num['features'] ?? [];
                $numbers[] = [
                    'phone_number' => $num['phone_number'],
                    'friendly_name' => $num['phone_number'],
                    'country_code' => $countryCode,
                    'type' => $num['phone_number_type'] ?? 'local',
                    'capabilities' => [
                        'sms' => in_array('sms', $features),
                        'mms' => in_array('mms', $features),
                        'voice' => in_array('voice', $features),
                    ],
                    'provider' => $provider->slug,
                    'region' => $num['region_information'][0]['region_name'] ?? null,
                    'locality' => $num['region_information'][0]['region_type'] ?? null,
                    'cost_usd' => $num['cost_information']['upfront_cost'] ?? null,
                    'monthly_cost' => $num['cost_information']['monthly_cost'] ?? null,
                ];
            }

            $existingNumbers = PhoneNumber::whereIn('phone_number', array_column($numbers, 'phone_number'))
                ->pluck('phone_number')->toArray();

            foreach ($numbers as &$num) {
                $num['already_in_inventory'] = in_array($num['phone_number'], $existingNumbers);
            }

            return response()->json([
                'provider' => $provider->slug,
                'country_code' => $countryCode,
                'total' => count($numbers),
                'numbers' => $numbers,
                'exchange_rate' => $this->getExchangeRate(),
                'markup_percent' => $this->getMarkupPercent(),
            ]);

        } catch (\Exception $e) {
            Log::error("Telnyx fetchNumbers error ({$countryCode}): ".$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch numbers from Telnyx: '.$e->getMessage(),
            ], 502);
        }
    }

    private function fetchTelnyxPricing(ApiProvider $provider, ?string $countryCode = null)
    {
        try {
            $rate = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            if ($countryCode) {
                // Fetch pricing for a specific country by searching for numbers
                $headers = $this->getTelnyxHeaders($provider);
                $response = Http::withHeaders($headers)->get('https://api.telnyx.com/v2/available_phone_numbers', [
                    'filter[country_code]' => strtoupper($countryCode),
                    'filter[limit]' => 3,
                ]);

                $prices = [];
                if ($response->successful()) {
                    $data = $response->json('data', []);
                    $seenTypes = [];
                    foreach ($data as $num) {
                        $type = $num['phone_number_type'] ?? 'local';
                        if (in_array($type, $seenTypes)) {
                            continue;
                        }
                        $seenTypes[] = $type;

                        $costInfo = $num['cost_information'] ?? [];
                        $monthly = (float) ($costInfo['monthly_cost'] ?? 1.00);
                        $upfront = (float) ($costInfo['upfront_cost'] ?? 0);

                        $prices[] = [
                            'number_type' => $type,
                            'base_price_usd' => $monthly,
                            'current_price_usd' => $monthly,
                            'base_price_ngn' => $this->usdToNgn($monthly),
                            'current_price_ngn' => $this->usdToNgn($monthly),
                            'upfront_usd' => $upfront,
                            'upfront_ngn' => $this->usdToNgn($upfront),
                        ];
                    }
                }

                return response()->json([
                    'provider' => $provider->slug,
                    'country' => $this->getCountryName(strtoupper($countryCode)),
                    'country_code' => strtoupper($countryCode),
                    'prices' => $prices,
                    'exchange_rate' => $rate,
                    'markup_percent' => $markup,
                ]);
            }

            // No specific country — return general pricing overview
            $results = [];
            $popularCountries = ['US', 'CA', 'GB', 'DE', 'FR', 'AU', 'NL', 'SE', 'IE', 'NZ'];

            foreach ($popularCountries as $code) {
                $results[] = [
                    'country' => $this->getCountryName($code),
                    'country_code' => $code,
                ];
            }

            return response()->json([
                'provider' => $provider->slug,
                'countries' => $results,
                'exchange_rate' => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('Telnyx fetchPricing error: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch pricing from Telnyx: '.$e->getMessage(),
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
                $code = $l1.$l2;
                $flag = mb_chr(0x1F1E6 + ord($l1) - ord('A')).mb_chr(0x1F1E6 + ord($l2) - ord('A'));
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

    /* ══════════════════════════════════════════════
       5SIM IMPLEMENTATION
       ══════════════════════════════════════════════ */

    /**
     * Fetch countries from 5sim (uses guest endpoint — no auth required).
     */
    private function fetch5SimCountries(ApiProvider $provider)
    {
        try {
            $fiveSim = FiveSimService::fromProvider($provider);
            $countriesRaw = $fiveSim->getCountries();

            $flagMap = $this->getCountryFlagMap();
            $dialCodeMap = $this->getDialCodeMap();
            $existingCodes = Country::pluck('code')->map(fn ($c) => strtoupper($c))->toArray();
            $rate = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            // 5sim returns an object keyed by country name: { "russia": {...}, "ukraine": {...} }
            $nameToIso = array_flip(FiveSimService::COUNTRY_MAP);

            $results = [];
            foreach ($countriesRaw as $countryName => $countryData) {
                $isoCode = $nameToIso[$countryName] ?? null;
                if (! $isoCode) {
                    continue;
                }

                $isoCode = strtoupper($isoCode);

                $results[] = [
                    'name' => $this->getCountryName($isoCode),
                    'code' => $isoCode,
                    'flag' => $flagMap[$isoCode] ?? '',
                    'dial_code' => $dialCodeMap[$isoCode] ?? '',
                    'twilio_code' => $isoCode,
                    'fivesim_name' => $countryName,
                    'price_usd' => 0.50,
                    'price_ngn' => $this->usdToNgn(0.50),
                    'already_exists' => in_array($isoCode, $existingCodes),
                ];
            }

            usort($results, function ($a, $b) {
                if ($a['already_exists'] !== $b['already_exists']) {
                    return $a['already_exists'] ? 1 : -1;
                }

                return strcmp($a['name'], $b['name']);
            });

            return response()->json([
                'provider' => $provider->slug,
                'total' => count($results),
                'new_count' => count(array_filter($results, fn ($r) => ! $r['already_exists'])),
                'countries' => $results,
                'exchange_rate' => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('5sim fetchCountries error: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch from 5sim: '.$e->getMessage(),
            ], 502);
        }
    }

    /**
     * Fetch available products from 5sim for a given country.
     * 5sim doesn't sell individual numbers — it sells service activations.
     * So "fetch numbers" for 5sim means "fetch available products/services".
     */
    private function fetch5SimProducts(ApiProvider $provider, string $countryCode)
    {
        try {
            $fiveSim = FiveSimService::fromProvider($provider);

            // Map ISO code to 5sim country name
            $countryName = FiveSimService::mapCountryCode(strtoupper($countryCode));

            // Fetch products for this country with "any" operator
            $products = $fiveSim->getProducts($countryName, 'any');

            $results = [];
            foreach ($products as $productName => $operators) {
                // Get the "any" operator data, or first available
                $opData = $operators['any'] ?? reset($operators);
                if (! $opData) {
                    continue;
                }

                $priceUsd = ($opData['cost'] ?? 0) / 100; // 5sim prices are in cents of RUB, but API returns in the account currency

                $results[] = [
                    'phone_number' => "5sim:{$productName}",
                    'friendly_name' => ucfirst(str_replace('_', ' ', $productName)),
                    'country_code' => strtoupper($countryCode),
                    'type' => 'activation',
                    'capabilities' => ['sms' => true, 'mms' => false, 'voice' => false],
                    'provider' => $provider->slug,
                    'product_name' => $productName,
                    'quantity' => $opData['count'] ?? 0,
                    'cost' => $opData['cost'] ?? 0,
                    'already_in_inventory' => false,
                ];
            }

            return response()->json([
                'provider' => $provider->slug,
                'country_code' => strtoupper($countryCode),
                'total' => count($results),
                'numbers' => $results,
                'note' => '5sim provides on-demand activation numbers, not pre-purchased inventory.',
                'exchange_rate' => $this->getExchangeRate(),
                'markup_percent' => $this->getMarkupPercent(),
            ]);

        } catch (\Exception $e) {
            Log::error("5sim fetchProducts error ({$countryCode}): ".$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch products from 5sim: '.$e->getMessage(),
            ], 502);
        }
    }

    /**
     * Fetch pricing from 5sim for a country or all countries.
     */
    private function fetch5SimPricing(ApiProvider $provider, ?string $countryCode = null)
    {
        try {
            $fiveSim = FiveSimService::fromProvider($provider);
            $rate = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            if ($countryCode) {
                $countryName = FiveSimService::mapCountryCode(strtoupper($countryCode));
                $prices = $fiveSim->getPrices($countryName);

                $results = [];

                // Prices are keyed by country, then by product
                $countryPrices = $prices[$countryName] ?? $prices;

                foreach ($countryPrices as $productName => $operators) {
                    if (! is_array($operators)) {
                        continue;
                    }

                    foreach ($operators as $opName => $opData) {
                        $costRub = (float) ($opData['cost'] ?? 0);
                        // Convert from RUB to USD approximately (1 RUB ≈ 0.011 USD)
                        $costUsd = round($costRub * 0.011, 4);

                        $results[] = [
                            'number_type' => ucfirst(str_replace('_', ' ', $productName)),
                            'operator' => $opName,
                            'base_price_usd' => $costUsd,
                            'current_price_usd' => $costUsd,
                            'base_price_ngn' => $this->usdToNgn($costUsd),
                            'current_price_ngn' => $this->usdToNgn($costUsd),
                            'quantity' => $opData['count'] ?? 0,
                        ];
                    }
                }

                return response()->json([
                    'provider' => $provider->slug,
                    'country' => $this->getCountryName(strtoupper($countryCode)),
                    'country_code' => strtoupper($countryCode),
                    'prices' => $results,
                    'exchange_rate' => $rate,
                    'markup_percent' => $markup,
                ]);
            }

            // No country specified — return list of available countries
            $countriesRaw = $fiveSim->getCountries();
            $nameToIso = array_flip(FiveSimService::COUNTRY_MAP);
            $results = [];

            foreach ($countriesRaw as $countryName => $data) {
                $isoCode = $nameToIso[$countryName] ?? null;
                if (! $isoCode) {
                    continue;
                }

                $results[] = [
                    'country' => $this->getCountryName(strtoupper($isoCode)),
                    'country_code' => strtoupper($isoCode),
                ];
            }

            return response()->json([
                'provider' => $provider->slug,
                'countries' => $results,
                'exchange_rate' => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('5sim fetchPricing error: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch pricing from 5sim: '.$e->getMessage(),
            ], 502);
        }
    }

    /* ══════════════════════════════════════════════
       FETCH & IMPORT SERVICES FROM PROVIDERS
       ══════════════════════════════════════════════ */

    /**
     * Fetch available services/products from a provider.
     * For 5sim: returns all products available on the platform.
     */
    public function fetchServices(Request $request)
    {
        $request->validate([
            'provider' => 'required|string',
            'country_code' => 'nullable|string|size:2',
        ]);

        $provider = $this->resolveProvider($request->provider);
        if (! $provider) {
            return response()->json(['message' => 'Provider not found or inactive.'], 422);
        }

        if (! $provider->isConfigured()) {
            return response()->json(['message' => "Provider '{$provider->name}' credentials not configured."], 422);
        }

        if ($provider->type === '5sim') {
            return $this->fetch5SimServices($provider, $request->country_code);
        }

        if ($provider->type === 'smspool') {
            return $this->fetchSmsPoolServices($provider);
        }

        return response()->json(['message' => "Service fetching not supported for provider type '{$provider->type}'."], 422);
    }

    /**
     * Import selected services into the services table.
     */
    public function importServices(Request $request)
    {
        $request->validate([
            'services' => 'required|array|min:1',
            'services.*.name' => 'required|string|max:100',
        ]);

        $imported = 0;
        $skipped = 0;

        foreach ($request->services as $item) {
            // Skip if service with this name already exists
            if (Service::whereRaw('LOWER(name) = ?', [strtolower($item['name'])])->exists()) {
                $skipped++;

                continue;
            }

            Service::create([
                'name' => $item['name'],
                'icon' => $item['icon'] ?? null,
                'color' => $item['color'] ?? '#33CCFF',
                'category' => $item['category'] ?? 'Other',
                'cost' => (float) ($item['cost'] ?? 100),
                'is_active' => true,
            ]);
            $imported++;
        }

        return response()->json([
            'message' => "Imported {$imported} services. Skipped {$skipped} duplicates.",
            'imported' => $imported,
            'skipped' => $skipped,
            'total' => Service::count(),
        ]);
    }

    /**
     * Fetch products from 5sim and format as importable services.
     */
    private function fetch5SimServices(ApiProvider $provider, ?string $countryCode = null)
    {
        try {
            $fiveSim = FiveSimService::fromProvider($provider);

            // Use a popular country to get product list with availability & pricing
            $country = $countryCode
                ? FiveSimService::mapCountryCode(strtoupper($countryCode))
                : 'usa';

            $products = $fiveSim->getProducts($country, 'any');

            // Well-known product metadata (icon URLs, colors, categories)
            $meta = $this->getProductMeta();

            $existingNames = Service::pluck('name')->map(fn ($n) => strtolower($n))->toArray();
            $rate = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            $results = [];
            foreach ($products as $productName => $data) {
                $displayName = $this->formatProductName($productName);
                $info = $meta[strtolower($productName)] ?? [];

                $priceRub = (float) ($data['Price'] ?? 0);
                $priceUsd = round($priceRub * 0.011, 4);
                $priceNgn = $this->usdToNgn($priceUsd);

                // Apply a minimum price floor of 100 NGN
                $suggestedCost = max($priceNgn, 100);

                $results[] = [
                    'name' => $displayName,
                    'slug' => $productName,
                    'icon' => $info['icon'] ?? null,
                    'color' => $info['color'] ?? '#33CCFF',
                    'category' => $info['category'] ?? 'Other',
                    'cost' => round($suggestedCost, 2),
                    'price_rub' => $priceRub,
                    'price_usd' => $priceUsd,
                    'quantity' => $data['Qty'] ?? 0,
                    'already_exists' => in_array(strtolower($displayName), $existingNames),
                ];
            }

            // Sort: new services first, then alphabetical
            usort($results, function ($a, $b) {
                if ($a['already_exists'] !== $b['already_exists']) {
                    return $a['already_exists'] ? 1 : -1;
                }
                // Prioritize well-known services
                $aPop = ! empty($this->getProductMeta()[strtolower($a['slug'])] ?? []);
                $bPop = ! empty($this->getProductMeta()[strtolower($b['slug'])] ?? []);
                if ($aPop !== $bPop) {
                    return $aPop ? -1 : 1;
                }

                return strcmp($a['name'], $b['name']);
            });

            return response()->json([
                'provider' => $provider->slug,
                'country_used' => $country,
                'total' => count($results),
                'new_count' => count(array_filter($results, fn ($r) => ! $r['already_exists'])),
                'services' => $results,
                'exchange_rate' => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('5sim fetchServices error: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch services from 5sim: '.$e->getMessage(),
            ], 502);
        }
    }

    /**
     * Format a 5sim product slug into a human-readable name.
     */
    private function formatProductName(string $slug): string
    {
        $special = [
            'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'instagram' => 'Instagram',
            'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'twitter' => 'Twitter / X',
            'google' => 'Google', 'gmail' => 'Gmail', 'youtube' => 'YouTube',
            'snapchat' => 'Snapchat', 'discord' => 'Discord', 'linkedin' => 'LinkedIn',
            'amazon' => 'Amazon', 'microsoft' => 'Microsoft', 'apple' => 'Apple',
            'uber' => 'Uber', 'netflix' => 'Netflix', 'spotify' => 'Spotify',
            'paypal' => 'PayPal', 'ebay' => 'eBay', 'yahoo' => 'Yahoo',
            'line' => 'LINE', 'viber' => 'Viber', 'wechat' => 'WeChat',
            'signal' => 'Signal', 'tinder' => 'Tinder', 'bumble' => 'Bumble',
            'steam' => 'Steam', 'openai' => 'OpenAI', 'coinbase' => 'Coinbase',
            'binance' => 'Binance', 'wise' => 'Wise', 'bolt' => 'Bolt',
            'airbnb' => 'Airbnb', 'alibaba' => 'Alibaba', 'aliexpress' => 'AliExpress',
            'alipay' => 'Alipay', 'adobe' => 'Adobe', 'dropbox' => 'Dropbox',
            'github' => 'GitHub', 'gitlab' => 'GitLab', 'notion' => 'Notion',
            'slack' => 'Slack', 'zoom' => 'Zoom', 'lyft' => 'Lyft',
            'grab' => 'Grab', 'shopee' => 'Shopee', 'lazada' => 'Lazada',
            'foodpanda' => 'Foodpanda', 'deliveroo' => 'Deliveroo',
            'nike' => 'Nike', 'adidas' => 'Adidas', 'zara' => 'Zara',
            'shein' => 'SHEIN', 'wish' => 'Wish', 'walmart' => 'Walmart',
            'temu' => 'Temu', 'reddit' => 'Reddit', 'pinterest' => 'Pinterest',
            'twitch' => 'Twitch', 'roblox' => 'Roblox', 'epicgames' => 'Epic Games',
        ];

        return $special[strtolower($slug)] ?? ucfirst(str_replace('_', ' ', $slug));
    }

    /**
     * Metadata for well-known services (icons, colors, categories).
     */
    private function getProductMeta(): array
    {
        return [
            // Messaging
            'whatsapp' => ['icon' => 'https://cdn.simpleicons.org/whatsapp/25D366', 'color' => '#25D366', 'category' => 'Messaging'],
            'telegram' => ['icon' => 'https://cdn.simpleicons.org/telegram/26A5E4', 'color' => '#0088CC', 'category' => 'Messaging'],
            'viber' => ['icon' => 'https://cdn.simpleicons.org/viber/7360F2', 'color' => '#665CAC', 'category' => 'Messaging'],
            'wechat' => ['icon' => 'https://cdn.simpleicons.org/wechat/07C160', 'color' => '#7BB32E', 'category' => 'Messaging'],
            'signal' => ['icon' => 'https://cdn.simpleicons.org/signal/3A76F0', 'color' => '#3A76F0', 'category' => 'Messaging'],
            'line' => ['icon' => 'https://cdn.simpleicons.org/line/00C300', 'color' => '#06C755', 'category' => 'Messaging'],
            'imo' => ['icon' => 'https://www.google.com/s2/favicons?domain=imo.im&sz=128', 'color' => '#00E5FF', 'category' => 'Messaging'],
            'kakaotalk' => ['icon' => 'https://cdn.simpleicons.org/kakaotalk/FFE812', 'color' => '#FFE812', 'category' => 'Messaging'],
            'skype' => ['icon' => 'https://cdn.simpleicons.org/skype/00AFF0', 'color' => '#00AFF0', 'category' => 'Messaging'],
            'messenger' => ['icon' => 'https://cdn.simpleicons.org/messenger/00B2FF', 'color' => '#00B2FF', 'category' => 'Messaging'],

            // Social
            'instagram' => ['icon' => 'https://cdn.simpleicons.org/instagram/E4405F', 'color' => '#E4405F', 'category' => 'Social'],
            'facebook' => ['icon' => 'https://cdn.simpleicons.org/facebook/1877F2', 'color' => '#1877F2', 'category' => 'Social'],
            'tiktok' => ['icon' => 'https://cdn.simpleicons.org/tiktok/FF0050', 'color' => '#FF0050', 'category' => 'Social'],
            'twitter' => ['icon' => 'https://cdn.simpleicons.org/twitter/1DA1F2', 'color' => '#1DA1F2', 'category' => 'Social'],
            'snapchat' => ['icon' => 'https://cdn.simpleicons.org/snapchat/FFFC00', 'color' => '#FFFC00', 'category' => 'Social'],
            'discord' => ['icon' => 'https://cdn.simpleicons.org/discord/5865F2', 'color' => '#5865F2', 'category' => 'Social'],
            'linkedin' => ['icon' => 'https://cdn.simpleicons.org/linkedin/0A66C2', 'color' => '#0A66C2', 'category' => 'Social'],
            'reddit' => ['icon' => 'https://cdn.simpleicons.org/reddit/FF4500', 'color' => '#FF4500', 'category' => 'Social'],
            'pinterest' => ['icon' => 'https://cdn.simpleicons.org/pinterest/BD081C', 'color' => '#E60023', 'category' => 'Social'],
            'tinder' => ['icon' => 'https://cdn.simpleicons.org/tinder/FF6B6B', 'color' => '#FE3C72', 'category' => 'Social'],
            'bumble' => ['icon' => 'https://cdn.simpleicons.org/bumble/FFC629', 'color' => '#FFC629', 'category' => 'Social'],
            'badoo' => ['icon' => 'https://cdn.simpleicons.org/badoo/FF7343', 'color' => '#FF7343', 'category' => 'Social'],
            'vk' => ['icon' => 'https://cdn.simpleicons.org/vk/0077FF', 'color' => '#0077FF', 'category' => 'Social'],
            'ok' => ['icon' => 'https://cdn.simpleicons.org/odnoklassniki/EE8208', 'color' => '#EE8208', 'category' => 'Social'],
            'weibo' => ['icon' => 'https://cdn.simpleicons.org/sinaweibo/E6162D', 'color' => '#E6162D', 'category' => 'Social'],
            'tumblr' => ['icon' => 'https://cdn.simpleicons.org/tumblr/36465D', 'color' => '#36465D', 'category' => 'Social'],
            'quora' => ['icon' => 'https://cdn.simpleicons.org/quora/B92B27', 'color' => '#B92B27', 'category' => 'Social'],
            'threads' => ['icon' => 'https://cdn.simpleicons.org/threads/FFFFFF', 'color' => '#000000', 'category' => 'Social'],

            // Email
            'google' => ['icon' => 'https://cdn.simpleicons.org/google/4285F4', 'color' => '#4285F4', 'category' => 'Email'],
            'gmail' => ['icon' => 'https://cdn.simpleicons.org/gmail/EA4335', 'color' => '#EA4335', 'category' => 'Email'],
            'yahoo' => ['icon' => 'https://cdn.simpleicons.org/yahoo/6001D2', 'color' => '#6001D2', 'category' => 'Email'],
            'outlook' => ['icon' => 'https://cdn.simpleicons.org/microsoftoutlook/0078D4', 'color' => '#0078D4', 'category' => 'Email'],
            'mailru' => ['icon' => 'https://cdn.simpleicons.org/maildotru/FF6600', 'color' => '#FF6600', 'category' => 'Email'],
            'yandex' => ['icon' => 'https://cdn.simpleicons.org/yandex/FF0000', 'color' => '#FF0000', 'category' => 'Email'],
            'protonmail' => ['icon' => 'https://cdn.simpleicons.org/protonmail/6D4AFF', 'color' => '#6D4AFF', 'category' => 'Email'],
            'microsoft' => ['icon' => 'https://cdn.simpleicons.org/microsoft/FFFFFF', 'color' => '#00A4EF', 'category' => 'Email'],

            // Shopping
            'amazon' => ['icon' => 'https://cdn.simpleicons.org/amazon/FF9900', 'color' => '#FF9900', 'category' => 'Shopping'],
            'ebay' => ['icon' => 'https://cdn.simpleicons.org/ebay/E53238', 'color' => '#E53238', 'category' => 'Shopping'],
            'aliexpress' => ['icon' => 'https://cdn.simpleicons.org/aliexpress/FF4747', 'color' => '#FF4747', 'category' => 'Shopping'],
            'alibaba' => ['icon' => 'https://cdn.simpleicons.org/alibabadotcom/FF6A00', 'color' => '#FF6A00', 'category' => 'Shopping'],
            'shopee' => ['icon' => 'https://cdn.simpleicons.org/shopee/EE4D2D', 'color' => '#EE4D2D', 'category' => 'Shopping'],
            'shopify' => ['icon' => 'https://cdn.simpleicons.org/shopify/7AB55C', 'color' => '#7AB55C', 'category' => 'Shopping'],
            'walmart' => ['icon' => 'https://cdn.simpleicons.org/walmart/0071CE', 'color' => '#0071CE', 'category' => 'Shopping'],
            'nike' => ['icon' => 'https://cdn.simpleicons.org/nike/FFFFFF', 'color' => '#000000', 'category' => 'Shopping'],
            'adidas' => ['icon' => 'https://cdn.simpleicons.org/adidas/FFFFFF', 'color' => '#000000', 'category' => 'Shopping'],
            'shein' => ['icon' => 'https://cdn.simpleicons.org/shein/FFFFFF', 'color' => '#000000', 'category' => 'Shopping'],
            'etsy' => ['icon' => 'https://cdn.simpleicons.org/etsy/F16521', 'color' => '#F16521', 'category' => 'Shopping'],
            'flipkart' => ['icon' => 'https://cdn.simpleicons.org/flipkart/2874F0', 'color' => '#2874F0', 'category' => 'Shopping'],
            'lazada' => ['icon' => 'https://www.google.com/s2/favicons?domain=lazada.com&sz=128', 'color' => '#0F146D', 'category' => 'Shopping'],
            'temu' => ['icon' => 'https://www.google.com/s2/favicons?domain=temu.com&sz=128', 'color' => '#FB7701', 'category' => 'Shopping'],
            'wish' => ['icon' => 'https://www.google.com/s2/favicons?domain=wish.com&sz=128', 'color' => '#2FB7EC', 'category' => 'Shopping'],
            'wildberries' => ['icon' => 'https://www.google.com/s2/favicons?domain=wildberries.ru&sz=128', 'color' => '#481173', 'category' => 'Shopping'],
            'ozon' => ['icon' => 'https://www.google.com/s2/favicons?domain=ozon.ru&sz=128', 'color' => '#005BFF', 'category' => 'Shopping'],
            'avito' => ['icon' => 'https://www.google.com/s2/favicons?domain=avito.ru&sz=128', 'color' => '#00AAFF', 'category' => 'Shopping'],
            'olx' => ['icon' => 'https://www.google.com/s2/favicons?domain=olx.com&sz=128', 'color' => '#002F34', 'category' => 'Shopping'],
            'mercari' => ['icon' => 'https://www.google.com/s2/favicons?domain=mercari.com&sz=128', 'color' => '#FF0211', 'category' => 'Shopping'],
            'rakuten' => ['icon' => 'https://cdn.simpleicons.org/rakuten/BF0000', 'color' => '#BF0000', 'category' => 'Shopping'],

            // Finance / Crypto
            'paypal' => ['icon' => 'https://cdn.simpleicons.org/paypal/00457C', 'color' => '#003087', 'category' => 'Finance'],
            'binance' => ['icon' => 'https://cdn.simpleicons.org/binance/F0B90B', 'color' => '#F0B90B', 'category' => 'Finance'],
            'coinbase' => ['icon' => 'https://cdn.simpleicons.org/coinbase/0052FF', 'color' => '#0052FF', 'category' => 'Finance'],
            'wise' => ['icon' => 'https://cdn.simpleicons.org/wise/9FE870', 'color' => '#9FE870', 'category' => 'Finance'],
            'revolut' => ['icon' => 'https://cdn.simpleicons.org/revolut/FFFFFF', 'color' => '#0075EB', 'category' => 'Finance'],
            'stripe' => ['icon' => 'https://cdn.simpleicons.org/stripe/635BFF', 'color' => '#635BFF', 'category' => 'Finance'],
            'cashapp' => ['icon' => 'https://cdn.simpleicons.org/cashapp/00C244', 'color' => '#00C244', 'category' => 'Finance'],
            'alipay' => ['icon' => 'https://cdn.simpleicons.org/alipay/1677FF', 'color' => '#1677FF', 'category' => 'Finance'],
            'skrill' => ['icon' => 'https://cdn.simpleicons.org/skrill/862165', 'color' => '#862165', 'category' => 'Finance'],
            'webmoney' => ['icon' => 'https://cdn.simpleicons.org/webmoney/036CB5', 'color' => '#036CB5', 'category' => 'Finance'],
            'qiwi' => ['icon' => 'https://www.google.com/s2/favicons?domain=qiwi.com&sz=128', 'color' => '#FF8C00', 'category' => 'Finance'],
            'kraken' => ['icon' => 'https://cdn.simpleicons.org/kraken/5741D9', 'color' => '#5741D9', 'category' => 'Finance'],
            'bybit' => ['icon' => 'https://www.google.com/s2/favicons?domain=bybit.com&sz=128', 'color' => '#F7A600', 'category' => 'Finance'],
            'okx' => ['icon' => 'https://www.google.com/s2/favicons?domain=okx.com&sz=128', 'color' => '#FFFFFF', 'category' => 'Finance'],

            // Transport / Delivery
            'uber' => ['icon' => 'https://cdn.simpleicons.org/uber/FFFFFF', 'color' => '#000000', 'category' => 'Transport'],
            'bolt' => ['icon' => 'https://cdn.simpleicons.org/bolt/34D186', 'color' => '#34D186', 'category' => 'Transport'],
            'lyft' => ['icon' => 'https://cdn.simpleicons.org/lyft/FF00BF', 'color' => '#FF00BF', 'category' => 'Transport'],
            'grab' => ['icon' => 'https://cdn.simpleicons.org/grab/00B14F', 'color' => '#00B14F', 'category' => 'Transport'],
            'gojek' => ['icon' => 'https://cdn.simpleicons.org/gojek/00AA13', 'color' => '#00AA13', 'category' => 'Transport'],
            'didi' => ['icon' => 'https://www.google.com/s2/favicons?domain=didiglobal.com&sz=128', 'color' => '#FF7A2A', 'category' => 'Transport'],
            'doordash' => ['icon' => 'https://cdn.simpleicons.org/doordash/FF3008', 'color' => '#FF3008', 'category' => 'Transport'],
            'ubereats' => ['icon' => 'https://cdn.simpleicons.org/ubereats/06C167', 'color' => '#06C167', 'category' => 'Transport'],
            'deliveroo' => ['icon' => 'https://cdn.simpleicons.org/deliveroo/00CCBC', 'color' => '#00CCBC', 'category' => 'Transport'],
            'foodpanda' => ['icon' => 'https://www.google.com/s2/favicons?domain=foodpanda.com&sz=128', 'color' => '#D70F64', 'category' => 'Transport'],
            'instacart' => ['icon' => 'https://cdn.simpleicons.org/instacart/43B02A', 'color' => '#43B02A', 'category' => 'Transport'],
            'glovo' => ['icon' => 'https://www.google.com/s2/favicons?domain=glovoapp.com&sz=128', 'color' => '#FFC244', 'category' => 'Transport'],

            // Gaming / Entertainment
            'steam' => ['icon' => 'https://cdn.simpleicons.org/steam/FFFFFF', 'color' => '#1B2838', 'category' => 'Gaming'],
            'twitch' => ['icon' => 'https://cdn.simpleicons.org/twitch/9146FF', 'color' => '#9146FF', 'category' => 'Gaming'],
            'netflix' => ['icon' => 'https://cdn.simpleicons.org/netflix/E50914', 'color' => '#E50914', 'category' => 'Entertainment'],
            'spotify' => ['icon' => 'https://cdn.simpleicons.org/spotify/1DB954', 'color' => '#1DB954', 'category' => 'Entertainment'],
            'youtube' => ['icon' => 'https://cdn.simpleicons.org/youtube/FF0000', 'color' => '#FF0000', 'category' => 'Entertainment'],
            'roblox' => ['icon' => 'https://cdn.simpleicons.org/roblox/FFFFFF', 'color' => '#000000', 'category' => 'Gaming'],
            'epicgames' => ['icon' => 'https://cdn.simpleicons.org/epicgames/FFFFFF', 'color' => '#313131', 'category' => 'Gaming'],
            'playstation' => ['icon' => 'https://cdn.simpleicons.org/playstation/003791', 'color' => '#003791', 'category' => 'Gaming'],
            'xbox' => ['icon' => 'https://cdn.simpleicons.org/xbox/107C10', 'color' => '#107C10', 'category' => 'Gaming'],
            'deezer' => ['icon' => 'https://cdn.simpleicons.org/deezer/FEAA2D', 'color' => '#FEAA2D', 'category' => 'Entertainment'],
            'hulu' => ['icon' => 'https://cdn.simpleicons.org/hulu/1CE783', 'color' => '#1CE783', 'category' => 'Entertainment'],
            'garena' => ['icon' => 'https://www.google.com/s2/favicons?domain=garena.com&sz=128', 'color' => '#FF5500', 'category' => 'Gaming'],
            'blizzard' => ['icon' => 'https://cdn.simpleicons.org/blizzardentertainment/148EFF', 'color' => '#148EFF', 'category' => 'Gaming'],

            // Tech / Productivity
            'apple' => ['icon' => 'https://cdn.simpleicons.org/apple/FFFFFF', 'color' => '#A2AAAD', 'category' => 'Other'],
            'openai' => ['icon' => 'https://cdn.simpleicons.org/openai/FFFFFF', 'color' => '#412991', 'category' => 'Other'],
            'zoom' => ['icon' => 'https://cdn.simpleicons.org/zoom/0B5CFF', 'color' => '#2D8CFF', 'category' => 'Other'],
            'slack' => ['icon' => 'https://cdn.simpleicons.org/slack/4A154B', 'color' => '#4A154B', 'category' => 'Other'],
            'notion' => ['icon' => 'https://cdn.simpleicons.org/notion/FFFFFF', 'color' => '#000000', 'category' => 'Other'],
            'github' => ['icon' => 'https://cdn.simpleicons.org/github/FFFFFF', 'color' => '#181717', 'category' => 'Other'],
            'gitlab' => ['icon' => 'https://cdn.simpleicons.org/gitlab/FC6D26', 'color' => '#FC6D26', 'category' => 'Other'],
            'adobe' => ['icon' => 'https://cdn.simpleicons.org/adobe/FF0000', 'color' => '#FF0000', 'category' => 'Other'],
            'dropbox' => ['icon' => 'https://cdn.simpleicons.org/dropbox/0061FF', 'color' => '#0061FF', 'category' => 'Other'],
            'canva' => ['icon' => 'https://cdn.simpleicons.org/canva/00C4CC', 'color' => '#00C4CC', 'category' => 'Other'],
            'figma' => ['icon' => 'https://cdn.simpleicons.org/figma/F24E1E', 'color' => '#F24E1E', 'category' => 'Other'],

            // Travel
            'airbnb' => ['icon' => 'https://cdn.simpleicons.org/airbnb/FF5A5F', 'color' => '#FF5A5F', 'category' => 'Travel'],
            'booking' => ['icon' => 'https://cdn.simpleicons.org/bookingdotcom/003580', 'color' => '#003580', 'category' => 'Travel'],

            // Other popular 5sim services
            'naver' => ['icon' => 'https://cdn.simpleicons.org/naver/03C75A', 'color' => '#03C75A', 'category' => 'Other'],
            'zalo' => ['icon' => 'https://www.google.com/s2/favicons?domain=zalo.me&sz=128', 'color' => '#0068FF', 'category' => 'Messaging'],
            'truecaller' => ['icon' => 'https://www.google.com/s2/favicons?domain=truecaller.com&sz=128', 'color' => '#0F85FF', 'category' => 'Other'],
            'fiverr' => ['icon' => 'https://cdn.simpleicons.org/fiverr/1DBF73', 'color' => '#1DBF73', 'category' => 'Other'],
            'upwork' => ['icon' => 'https://cdn.simpleicons.org/upwork/14A800', 'color' => '#14A800', 'category' => 'Other'],
            'duolingo' => ['icon' => 'https://cdn.simpleicons.org/duolingo/58CC02', 'color' => '#58CC02', 'category' => 'Other'],
            'strava' => ['icon' => 'https://cdn.simpleicons.org/strava/FC4C02', 'color' => '#FC4C02', 'category' => 'Other'],
            'zomato' => ['icon' => 'https://cdn.simpleicons.org/zomato/E23744', 'color' => '#E23744', 'category' => 'Transport'],
        ];
    }

    /* ══════════════════════════════════════════════
       SMSPOOL IMPLEMENTATION
       ══════════════════════════════════════════════ */

    /**
     * Fetch countries supported by SMSPool.
     * Uses the static COUNTRY_MAP — SMSPool's /country/retrieve_all returns a flat list
     * but we already have all the IDs mapped, so we build the response from the map directly
     * to avoid an unnecessary network round-trip at the admin level.
     */
    private function fetchSmsPoolCountries(ApiProvider $provider)
    {
        try {
            $smsPool = SmsPoolService::fromProvider($provider);

            // Fetch the live list so we only show countries SMSPool actually has right now
            $rawCountries = $smsPool->getCountries();

            // Build a reverse map: smspool_id → ISO code
            $idToIso = array_flip(SmsPoolService::COUNTRY_MAP);

            $flagMap = $this->getCountryFlagMap();
            $dialCodeMap = $this->getDialCodeMap();
            $existingCodes = Country::pluck('code')->map(fn ($c) => strtoupper($c))->toArray();
            $rate = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            $results = [];

            foreach ($rawCountries as $entry) {
                // SMSPool returns either [{ID, name}] or {ID: name} depending on version
                $smsPoolId = is_array($entry) ? (int) ($entry['ID'] ?? $entry['id'] ?? -1) : -1;
                $countryName = is_array($entry) ? ($entry['name'] ?? '') : $entry;

                // Find the ISO code from our map
                $isoCode = $idToIso[$smsPoolId] ?? null;
                if (! $isoCode) {
                    continue;
                }

                $isoCode = strtoupper($isoCode);
                $priceUsd = 0.10; // SMSPool virtual activations are cheap; use as a display estimate

                $results[] = [
                    'name' => $this->getCountryName($isoCode) ?: $countryName,
                    'code' => $isoCode,
                    'flag' => $flagMap[$isoCode] ?? '',
                    'dial_code' => $dialCodeMap[$isoCode] ?? '',
                    'twilio_code' => $isoCode,
                    'smspool_id' => $smsPoolId,
                    'price_usd' => $priceUsd,
                    'price_ngn' => $this->usdToNgn($priceUsd),
                    'already_exists' => in_array($isoCode, $existingCodes),
                ];
            }

            // If the live call returned nothing usable, fall back to the static map
            if (empty($results)) {
                foreach (SmsPoolService::COUNTRY_MAP as $iso => $id) {
                    $iso = strtoupper($iso);
                    $results[] = [
                        'name' => $this->getCountryName($iso),
                        'code' => $iso,
                        'flag' => $flagMap[$iso] ?? '',
                        'dial_code' => $dialCodeMap[$iso] ?? '',
                        'twilio_code' => $iso,
                        'smspool_id' => $id,
                        'price_usd' => 0.10,
                        'price_ngn' => $this->usdToNgn(0.10),
                        'already_exists' => in_array($iso, $existingCodes),
                    ];
                }
            }

            usort($results, function ($a, $b) {
                if ($a['already_exists'] !== $b['already_exists']) {
                    return $a['already_exists'] ? 1 : -1;
                }

                return strcmp($a['name'], $b['name']);
            });

            return response()->json([
                'provider' => $provider->slug,
                'total' => count($results),
                'new_count' => count(array_filter($results, fn ($r) => ! $r['already_exists'])),
                'countries' => $results,
                'exchange_rate' => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('SMSPool fetchCountries error: '.$e->getMessage());

            return response()->json(['message' => 'Failed to fetch from SMSPool: '.$e->getMessage()], 502);
        }
    }

    /**
     * Fetch available services for a country from SMSPool.
     * "Numbers" in the admin UI map to on-demand activations, not pre-purchased inventory.
     */
    private function fetchSmsPoolNumbers(ApiProvider $provider, ?string $countryCode = null)
    {
        try {
            $smsPool = SmsPoolService::fromProvider($provider);

            $smsPoolCountryId = $countryCode
                ? SmsPoolService::mapCountryCode(strtoupper($countryCode))
                : null;

            $rawServices = $smsPool->getServices($smsPoolCountryId);

            $results = [];
            $meta = $this->getProductMeta();

            foreach ($rawServices as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $name = $entry['name'] ?? $entry['service'] ?? 'Unknown';
                $slug = strtolower(preg_replace('/\s+/', '_', $name));
                $info = $meta[$slug] ?? $meta[strtolower($name)] ?? [];

                $results[] = [
                    'phone_number' => "smspool:{$slug}",
                    'friendly_name' => $name,
                    'country_code' => $countryCode ? strtoupper($countryCode) : 'ALL',
                    'type' => 'activation',
                    'capabilities' => ['sms' => true, 'mms' => false, 'voice' => false],
                    'provider' => $provider->slug,
                    'service_name' => $slug,
                    'icon' => $info['icon'] ?? null,
                    'color' => $info['color'] ?? '#33CCFF',
                    'quantity' => null, // SMSPool doesn't expose stock in service list
                    'already_in_inventory' => false,
                ];
            }

            return response()->json([
                'provider' => $provider->slug,
                'country_code' => $countryCode ? strtoupper($countryCode) : 'ALL',
                'total' => count($results),
                'numbers' => $results,
                'note' => 'SMSPool provides on-demand activation numbers, not pre-purchased inventory.',
                'exchange_rate' => $this->getExchangeRate(),
                'markup_percent' => $this->getMarkupPercent(),
            ]);

        } catch (\Exception $e) {
            Log::error('SMSPool fetchNumbers error: '.$e->getMessage());

            return response()->json(['message' => 'Failed to fetch services from SMSPool: '.$e->getMessage()], 502);
        }
    }

    /**
     * Fetch pricing estimates from SMSPool.
     * SMSPool doesn't expose a public pricing list, so we show per-service/country
     * availability and note that pricing is determined at purchase time.
     */
    private function fetchSmsPoolPricing(ApiProvider $provider, ?string $countryCode = null)
    {
        try {
            $smsPool = SmsPoolService::fromProvider($provider);
            $rate = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            if ($countryCode) {
                $smsPoolCountryId = SmsPoolService::mapCountryCode(strtoupper($countryCode));

                if ($smsPoolCountryId === null) {
                    return response()->json(['message' => "Country {$countryCode} is not mapped in SMSPool."], 422);
                }

                $rawServices = $smsPool->getServices($smsPoolCountryId);

                $prices = [];
                foreach ($rawServices as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    $name = $entry['name'] ?? $entry['service'] ?? 'Unknown';
                    $priceUsd = isset($entry['price']) ? (float) $entry['price'] : 0.10;

                    $prices[] = [
                        'number_type' => $name,
                        'base_price_usd' => $priceUsd,
                        'current_price_usd' => $priceUsd,
                        'base_price_ngn' => $this->usdToNgn($priceUsd),
                        'current_price_ngn' => $this->usdToNgn($priceUsd),
                        'quantity' => $entry['amount'] ?? null,
                    ];
                }

                return response()->json([
                    'provider' => $provider->slug,
                    'country' => $this->getCountryName(strtoupper($countryCode)),
                    'country_code' => strtoupper($countryCode),
                    'prices' => $prices,
                    'exchange_rate' => $rate,
                    'markup_percent' => $markup,
                    'note' => 'SMSPool prices are determined dynamically at purchase time.',
                ]);
            }

            // No country — return all mapped countries as a browseable list
            $results = [];
            foreach (SmsPoolService::COUNTRY_MAP as $iso => $id) {
                $results[] = [
                    'country' => $this->getCountryName(strtoupper($iso)),
                    'country_code' => strtoupper($iso),
                    'smspool_id' => $id,
                ];
            }

            return response()->json([
                'provider' => $provider->slug,
                'countries' => $results,
                'exchange_rate' => $rate,
                'markup_percent' => $markup,
                'note' => 'Select a country to see per-service pricing.',
            ]);

        } catch (\Exception $e) {
            Log::error('SMSPool fetchPricing error: '.$e->getMessage());

            return response()->json(['message' => 'Failed to fetch pricing from SMSPool: '.$e->getMessage()], 502);
        }
    }

    /**
     * Fetch all services available on SMSPool and format them as importable service entries.
     * Uses the static SERVICE_MAP + live service list, enriched with known metadata.
     */
    private function fetchSmsPoolServices(ApiProvider $provider)
    {
        try {
            $smsPool = SmsPoolService::fromProvider($provider);

            // Fetch live service list (no country filter = all services)
            $rawServices = $smsPool->getServices();

            $meta = $this->getProductMeta();
            $existingNames = Service::pluck('name')->map(fn ($n) => strtolower($n))->toArray();
            $rate = $this->getExchangeRate();
            $markup = $this->getMarkupPercent();

            $results = [];

            // Build from the live API response
            foreach ($rawServices as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $name = $entry['name'] ?? $entry['service'] ?? null;
                if (! $name) {
                    continue;
                }

                $slug = strtolower(preg_replace('/\s+/', '_', $name));
                $info = $meta[$slug] ?? $meta[strtolower($name)] ?? [];
                $priceUsd = isset($entry['price']) ? (float) $entry['price'] : 0.10;
                $priceNgn = max($this->usdToNgn($priceUsd), 100);

                $results[$slug] = [
                    'name' => $name,
                    'slug' => $slug,
                    'icon' => $info['icon'] ?? null,
                    'color' => $info['color'] ?? '#33CCFF',
                    'category' => $info['category'] ?? 'Other',
                    'cost' => round($priceNgn, 2),
                    'price_usd' => $priceUsd,
                    'already_exists' => in_array(strtolower($name), $existingNames),
                ];
            }

            // Supplement with any entries in our static SERVICE_MAP that didn't appear in the live list
            foreach (SmsPoolService::SERVICE_MAP as $slug => $id) {
                if (isset($results[$slug])) {
                    continue;
                }

                $info = $meta[$slug] ?? [];
                $displayName = $this->formatProductName($slug);

                $results[$slug] = [
                    'name' => $displayName,
                    'slug' => $slug,
                    'icon' => $info['icon'] ?? null,
                    'color' => $info['color'] ?? '#33CCFF',
                    'category' => $info['category'] ?? 'Other',
                    'cost' => 100.00,
                    'price_usd' => 0.10,
                    'already_exists' => in_array(strtolower($displayName), $existingNames),
                ];
            }

            $results = array_values($results);

            usort($results, function ($a, $b) {
                if ($a['already_exists'] !== $b['already_exists']) {
                    return $a['already_exists'] ? 1 : -1;
                }

                return strcmp($a['name'], $b['name']);
            });

            return response()->json([
                'provider' => $provider->slug,
                'total' => count($results),
                'new_count' => count(array_filter($results, fn ($r) => ! $r['already_exists'])),
                'services' => $results,
                'exchange_rate' => $rate,
                'markup_percent' => $markup,
            ]);

        } catch (\Exception $e) {
            Log::error('SMSPool fetchServices error: '.$e->getMessage());

            return response()->json(['message' => 'Failed to fetch services from SMSPool: '.$e->getMessage()], 502);
        }
    }
}
