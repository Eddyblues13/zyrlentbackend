<?php

namespace App\Services;

use App\Models\ApiProvider;
use App\Models\Country;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 5SIM API Service
 *
 * Handles all communication with the 5sim.net API:
 *  - Buy activation numbers
 *  - Check order / get SMS
 *  - Cancel orders
 *  - Finish orders
 *  - Get countries, products, and prices
 *
 * API Docs: https://5sim.net/docs
 */
class FiveSimService
{
    /**
     * ISO code → 5sim country name mapping.
     * Exposed as a constant so other classes (e.g. ProviderFetchController) can reverse-lookup.
     */
    public const COUNTRY_MAP = [
        'AF' => 'afghanistan', 'AL' => 'albania', 'DZ' => 'algeria',
        'AO' => 'angola', 'AR' => 'argentina', 'AM' => 'armenia',
        'AU' => 'australia', 'AT' => 'austria', 'AZ' => 'azerbaijan',
        'BS' => 'bahamas', 'BH' => 'bahrain', 'BD' => 'bangladesh',
        'BB' => 'barbados', 'BY' => 'belarus', 'BE' => 'belgium',
        'BZ' => 'belize', 'BJ' => 'benin', 'BO' => 'bolivia',
        'BA' => 'bih', 'BW' => 'botswana', 'BR' => 'brazil',
        'BG' => 'bulgaria', 'BF' => 'burkinafaso', 'BI' => 'burundi',
        'KH' => 'cambodia', 'CM' => 'cameroon', 'CA' => 'canada',
        'CV' => 'capeverde', 'TD' => 'chad', 'CL' => 'chile',
        'CN' => 'china', 'CO' => 'colombia', 'KM' => 'comoros',
        'CG' => 'congo', 'CR' => 'costarica', 'HR' => 'croatia',
        'CY' => 'cyprus', 'CZ' => 'czech', 'DK' => 'denmark',
        'DJ' => 'djibouti', 'DO' => 'dominicana', 'EC' => 'ecuador',
        'EG' => 'egypt', 'GB' => 'england', 'GQ' => 'equatorialguinea',
        'EE' => 'estonia', 'ET' => 'ethiopia', 'FI' => 'finland',
        'FR' => 'france', 'GF' => 'frenchguiana', 'GA' => 'gabon',
        'GM' => 'gambia', 'GE' => 'georgia', 'DE' => 'germany',
        'GH' => 'ghana', 'GR' => 'greece', 'GT' => 'guatemala',
        'GN' => 'guinea', 'GW' => 'guineabissau', 'GY' => 'guyana',
        'HT' => 'haiti', 'HN' => 'honduras', 'HK' => 'hongkong',
        'HU' => 'hungary', 'IN' => 'india', 'ID' => 'indonesia',
        'IE' => 'ireland', 'IL' => 'israel', 'IT' => 'italy',
        'CI' => 'ivorycoast', 'JM' => 'jamaica', 'JP' => 'japan',
        'JO' => 'jordan', 'KZ' => 'kazakhstan', 'KE' => 'kenya',
        'KW' => 'kuwait', 'KG' => 'kyrgyzstan', 'LA' => 'laos',
        'LV' => 'latvia', 'LS' => 'lesotho', 'LR' => 'liberia',
        'LT' => 'lithuania', 'LU' => 'luxembourg', 'MO' => 'macau',
        'MG' => 'madagascar', 'MW' => 'malawi', 'MY' => 'malaysia',
        'MV' => 'maldives', 'MR' => 'mauritania', 'MU' => 'mauritius',
        'MX' => 'mexico', 'MD' => 'moldova', 'MN' => 'mongolia',
        'ME' => 'montenegro', 'MA' => 'morocco', 'MZ' => 'mozambique',
        'NA' => 'namibia', 'NP' => 'nepal', 'NL' => 'netherlands',
        'NC' => 'newcaledonia', 'NZ' => 'newzealand',
        'NI' => 'nicaragua', 'NG' => 'nigeria', 'MK' => 'northmacedonia',
        'NO' => 'norway', 'OM' => 'oman', 'PK' => 'pakistan',
        'PA' => 'panama', 'PG' => 'papuanewguinea', 'PY' => 'paraguay',
        'PE' => 'peru', 'PH' => 'philippines', 'PL' => 'poland',
        'PT' => 'portugal', 'PR' => 'puertorico', 'RE' => 'reunion',
        'RO' => 'romania', 'RU' => 'russia', 'RW' => 'rwanda',
        'SA' => 'saudiarabia', 'SN' => 'senegal', 'RS' => 'serbia',
        'SC' => 'seychelles', 'SL' => 'sierraleone', 'SK' => 'slovakia',
        'SI' => 'slovenia', 'SB' => 'solomonislands', 'ZA' => 'southafrica',
        'ES' => 'spain', 'LK' => 'srilanka', 'SR' => 'suriname',
        'SZ' => 'swaziland', 'SE' => 'sweden', 'CH' => 'switzerland',
        'TW' => 'taiwan', 'TJ' => 'tajikistan', 'TZ' => 'tanzania',
        'TH' => 'thailand', 'TT' => 'tit', 'TG' => 'togo',
        'TN' => 'tunisia', 'TR' => 'turkey', 'TM' => 'turkmenistan',
        'UG' => 'uganda', 'UA' => 'ukraine', 'AE' => 'uae',
        'US' => 'usa', 'UY' => 'uruguay', 'UZ' => 'uzbekistan',
        'VE' => 'venezuela', 'VN' => 'vietnam', 'ZM' => 'zambia',
        'ZW' => 'zimbabwe', 'KR' => 'southkorea', 'SG' => 'singapore',
    ];


    private string $apiKey;
    private string $baseUrl = 'https://5sim.net/v1';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Create from an ApiProvider model.
     */
    public static function fromProvider(ApiProvider $provider): self
    {
        $apiKey = $provider->getCredential('api_key');
        if (!$apiKey) {
            throw new \Exception('5SIM API key not configured');
        }
        return new self($apiKey);
    }

    /**
     * Make an authenticated GET request.
     */
    private function get(string $url, array $query = [])
    {
        $fullUrl = $this->baseUrl . $url;

        Log::debug("5SIM API request: GET {$fullUrl}", ['query' => $query]);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept' => 'application/json',
        ])->timeout(30)->get($fullUrl, $query);

        $body = $response->body();

        Log::debug("5SIM API response [{$response->status()}]: {$body}", ['url' => $url]);

        if (!$response->successful()) {
            Log::error("5SIM API error [{$response->status()}]: {$body}", ['url' => $url]);
            throw new \Exception("5SIM API error: {$body}");
        }

        $json = $response->json();

        // response->json() returns null when body is not valid JSON
        if ($json === null && !empty($body)) {
            Log::warning("5SIM API returned non-JSON body: {$body}", ['url' => $url]);
            throw new \Exception("5SIM API returned unexpected response: {$body}");
        }

        return $json ?? [];
    }

    /**
     * Make an unauthenticated GET request (guest endpoints).
     */
    private function guestGet(string $url, array $query = [])
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
        ])->timeout(30)->get($this->baseUrl . $url, $query);

        if (!$response->successful()) {
            $body = $response->body();
            Log::error("5SIM guest API error [{$response->status()}]: {$body}", ['url' => $url]);
            throw new \Exception("5SIM API error: {$body}");
        }

        return $response->json();
    }

    // ═══════════════════════════════════════════════════════
    //  USER / BALANCE
    // ═══════════════════════════════════════════════════════

    /**
     * Get profile including balance.
     */
    public function getProfile(): array
    {
        return $this->get('/user/profile');
    }

    /**
     * Get current balance.
     */
    public function getBalance(): float
    {
        $profile = $this->getProfile();
        return (float) ($profile['balance'] ?? 0);
    }

    // ═══════════════════════════════════════════════════════
    //  PRODUCTS & PRICES (guest — no auth needed)
    // ═══════════════════════════════════════════════════════

    /**
     * Get all products for a country/operator.
     * GET /v1/guest/products/{country}/{operator}
     */
    public function getProducts(string $country, string $operator = 'any'): array
    {
        return $this->guestGet("/guest/products/{$country}/{$operator}");
    }

    /**
     * Get all prices.
     * GET /v1/guest/prices
     */
    public function getPrices(?string $country = null, ?string $product = null): array
    {
        $query = [];
        if ($country) $query['country'] = $country;
        if ($product) $query['product'] = $product;
        return $this->guestGet('/guest/prices', $query);
    }

    /**
     * Get countries list.
     * GET /v1/guest/countries
     */
    public function getCountries(): array
    {
        return $this->guestGet('/guest/countries');
    }

    // ═══════════════════════════════════════════════════════
    //  PURCHASE
    // ═══════════════════════════════════════════════════════

    /**
     * Buy an activation number.
     * GET /v1/user/buy/activation/{country}/{operator}/{product}
     *
     * Returns: { id, phone, operator, product, price, status, expires, sms, created_at, country }
     */
    public function buyActivationNumber(string $country, string $product, string $operator = 'any'): array
    {
        return $this->get("/user/buy/activation/{$country}/{$operator}/{$product}");
    }

    /**
     * Buy a hosting (rental) number.
     * GET /v1/user/buy/hosting/{country}/{operator}/{product}
     */
    public function buyHostingNumber(string $country, string $product, string $operator = 'any'): array
    {
        return $this->get("/user/buy/hosting/{$country}/{$operator}/{$product}");
    }

    // ═══════════════════════════════════════════════════════
    //  ORDER MANAGEMENT
    // ═══════════════════════════════════════════════════════

    /**
     * Check order status and get SMS.
     * GET /v1/user/check/{id}
     *
     * Returns: { id, phone, product, price, status, expires, sms: [{created_at, date, sender, text, code}], ... }
     */
    public function checkOrder(int $orderId): array
    {
        return $this->get("/user/check/{$orderId}");
    }

    /**
     * Finish an order (mark as done).
     * GET /v1/user/finish/{id}
     */
    public function finishOrder(int $orderId): array
    {
        return $this->get("/user/finish/{$orderId}");
    }

    /**
     * Cancel an order (get refund on 5sim).
     * GET /v1/user/cancel/{id}
     */
    public function cancelOrder(int $orderId): array
    {
        return $this->get("/user/cancel/{$orderId}");
    }

    /**
     * Ban an order's number.
     * GET /v1/user/ban/{id}
     */
    public function banOrder(int $orderId): array
    {
        return $this->get("/user/ban/{$orderId}");
    }

    // ═══════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════

    /**
     * Map ISO country code (e.g. "US") to 5sim country name (e.g. "usa").
     */
    public static function mapCountryCode(string $isoCode): string
    {
        return self::COUNTRY_MAP[strtoupper($isoCode)] ?? strtolower($isoCode);
    }

    /**
     * Map service name/slug to 5sim product name.
     */
    public static function mapServiceToProduct(string $serviceName): string
    {
        $name = strtolower(trim($serviceName));

        $map = [
            'whatsapp'  => 'whatsapp',
            'telegram'  => 'telegram',
            'instagram' => 'instagram',
            'facebook'  => 'facebook',
            'tiktok'    => 'tiktok',
            'twitter'   => 'twitter',
            'x'         => 'twitter',
            'google'    => 'google',
            'youtube'   => 'google',
            'gmail'     => 'google',
            'snapchat'  => 'snapchat',
            'discord'   => 'discord',
            'linkedin'  => 'linkedin',
            'amazon'    => 'amazon',
            'microsoft' => 'microsoft',
            'apple'     => 'apple',
            'uber'      => 'uber',
            'netflix'   => 'netflix',
            'spotify'   => 'spotify',
            'paypal'    => 'paypal',
            'ebay'      => 'ebay',
            'yahoo'     => 'yahoo',
            'line'      => 'line',
            'viber'     => 'viber',
            'wechat'    => 'wechat',
            'signal'    => 'signal',
            'tinder'    => 'tinder',
            'bumble'    => 'bumble',
            'steam'     => 'steam',
            'openai'    => 'openai',
            'chatgpt'   => 'openai',
            'claude'    => 'claudeai',
            'coinbase'  => 'coinbase',
            'binance'   => 'binance',
            'wise'      => 'wise',
            'bolt'      => 'bolt',
            'grab'      => 'grabtaxi',
            'shopee'    => 'shopee',
            'lazada'    => 'lazada',
            'airbnb'    => 'airbnb',
            'nike'      => 'nike',
            'zara'      => 'zara',
            'temu'      => 'temu',
            'reddit'    => 'reddit',
            'pinterest' => 'pinterest',
            'badoo'     => 'badoo',
            'skype'     => 'skype',
            'kakao'     => 'kakaotalk',
            'kakaotalk' => 'kakaotalk',
            'naver'     => 'naver',
            'imo'       => 'imo',
            'other'     => 'other',
        ];

        // Direct match
        if (isset($map[$name])) {
            return $map[$name];
        }

        // Partial match
        foreach ($map as $key => $product) {
            if (str_contains($name, $key)) {
                return $product;
            }
        }

        // Fallback: use the slug directly (5sim has hundreds of products)
        return str_replace([' ', '-'], '', $name);
    }
}
