<?php

namespace App\Services;

use App\Models\ApiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMSPool API Service
 *
 * Handles all communication with the smspool.net API:
 *  - Purchase SMS numbers
 *  - Check order status / get SMS
 *  - Cancel orders
 *  - Retrieve countries and services
 *
 * API Base: https://api.smspool.net
 *
 * Status codes from SMSPool:
 *   1 = Pending (waiting for SMS)
 *   2 = Expired
 *   3 = Completed (SMS received)
 *   4 = Resend
 *   5 = Cancelled
 *   6 = Refunded
 *   7 = Processing
 *   8 = Activating
 */
class SmsPoolService
{
    /**
     * ISO code → SMSPool country ID mapping.
     * These IDs come from SMSPool's /country/retrieve_all endpoint.
     * Update as needed when SMSPool adds new countries.
     */
    public const COUNTRY_MAP = [
        'US' => 1,   'GB' => 10,  'CA' => 36,  'AU' => 21,
        'DE' => 43,  'FR' => 16,  'NL' => 48,  'ES' => 28,
        'IT' => 86,  'BR' => 12,  'IN' => 22,  'RU' => 0,
        'CN' => 3,   'ID' => 6,   'PH' => 4,   'MX' => 54,
        'NG' => 19,  'ZA' => 31,  'KE' => 5,   'GH' => 40,
        'PK' => 14,  'BD' => 20,  'VN' => 47,  'TH' => 51,
        'MY' => 7,   'SG' => 65,  'HK' => 13,  'TW' => 55,
        'JP' => 87,  'KR' => 114, 'TR' => 39,  'EG' => 29,
        'SA' => 53,  'AE' => 95,  'PL' => 15,  'RO' => 32,
        'UA' => 2,   'CZ' => 63,  'SE' => 46,  'NO' => 73,
        'DK' => 72,  'FI' => 77,  'AT' => 50,  'CH' => 94,
        'BE' => 82,  'PT' => 117, 'IE' => 23,  'NZ' => 33,
        'AR' => 26,  'CL' => 38,  'CO' => 37,  'PE' => 66,
        'KZ' => 42,  'UZ' => 40,  'GE' => 79,  'IL' => 78,
        'JO' => 116, 'LB' => 115, 'MA' => 37,  'TN' => 89,
        'ET' => 69,  'TZ' => 9,   'UG' => 75,  'CM' => 41,
        'CI' => 27,  'SN' => 61,  'MM' => 8,   'KH' => 24,
        'LA' => 45,  'NP' => 81,  'LK' => 129, 'HR' => 83,
        'HU' => 84,  'BG' => 56,  'RS' => 34,  'SK' => 62,
        'SI' => 60,  'LT' => 44,  'LV' => 49,  'EE' => 34,
    ];

    /**
     * Service name → SMSPool service ID mapping.
     * These IDs come from SMSPool's /service/retrieve_all endpoint.
     */
    public const SERVICE_MAP = [
        'whatsapp'  => 1,
        'telegram'  => 15,
        'instagram' => 2,
        'facebook'  => 3,
        'tiktok'    => 12,
        'twitter'   => 5,
        'x'         => 5,
        'google'    => 9,
        'youtube'   => 9,
        'gmail'     => 9,
        'snapchat'  => 24,
        'discord'   => 10,
        'linkedin'  => 22,
        'amazon'    => 28,
        'microsoft' => 19,
        'apple'     => 20,
        'uber'      => 26,
        'netflix'   => 52,
        'spotify'   => 29,
        'paypal'    => 61,
        'ebay'      => 63,
        'yahoo'     => 17,
        'line'      => 14,
        'viber'     => 238,
        'wechat'    => 18,
        'signal'    => 96,
        'tinder'    => 38,
        'bumble'    => 80,
        'steam'     => 27,
        'openai'    => 584,
        'chatgpt'   => 584,
        'coinbase'  => 43,
        'binance'   => 44,
        'wise'      => 109,
        'bolt'      => 100,
        'shopee'    => 122,
        'airbnb'    => 49,
        'nike'      => 188,
        'temu'      => 672,
        'reddit'    => 88,
        'pinterest' => 72,
        'badoo'     => 76,
        'skype'     => 56,
        'kakaotalk' => 16,
        'naver'     => 111,
        'imo'       => 23,
        'other'     => 999,
    ];

    private string $apiKey;
    private string $baseUrl = 'https://api.smspool.net';

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
            throw new \Exception('SMSPool API key not configured');
        }
        return new self($apiKey);
    }

    /**
     * Make an authenticated POST request.
     */
    private function post(string $endpoint, array $params = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $params['key'] = $this->apiKey;

        Log::debug("SMSPool API request: POST {$url}", ['params' => array_diff_key($params, ['key' => ''])]);

        $response = Http::asForm()
            ->timeout(30)
            ->post($url, $params);

        $body = $response->body();

        Log::debug("SMSPool API response [{$response->status()}]: {$body}", ['endpoint' => $endpoint]);

        if (!$response->successful()) {
            Log::error("SMSPool API error [{$response->status()}]: {$body}", ['endpoint' => $endpoint]);
            throw new \Exception("SMSPool API error: {$body}");
        }

        $json = $response->json();

        if ($json === null && !empty($body)) {
            Log::warning("SMSPool API returned non-JSON body: {$body}", ['endpoint' => $endpoint]);
            throw new \Exception("SMSPool API returned unexpected response: {$body}");
        }

        return $json ?? [];
    }

    /**
     * Make an unauthenticated GET request (public endpoints).
     */
    private function get(string $endpoint, array $query = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $response = Http::timeout(30)->get($url, $query);

        if (!$response->successful()) {
            $body = $response->body();
            Log::error("SMSPool guest API error [{$response->status()}]: {$body}", ['endpoint' => $endpoint]);
            throw new \Exception("SMSPool API error: {$body}");
        }

        return $response->json() ?? [];
    }

    // ═══════════════════════════════════════════════════════
    //  BALANCE
    // ═══════════════════════════════════════════════════════

    /**
     * Get current balance.
     * POST /request/balance
     */
    public function getBalance(): float
    {
        $result = $this->post('/request/balance');
        return (float) ($result['balance'] ?? 0);
    }

    // ═══════════════════════════════════════════════════════
    //  COUNTRIES & SERVICES (public — no auth needed)
    // ═══════════════════════════════════════════════════════

    /**
     * Get all available countries.
     * GET /country/retrieve_all
     */
    public function getCountries(): array
    {
        return $this->get('/country/retrieve_all');
    }

    /**
     * Get all available services.
     * GET /service/retrieve_all
     */
    public function getServices(?int $countryId = null): array
    {
        $params = [];
        if ($countryId !== null) {
            $params['country'] = $countryId;
        }
        return $this->get('/service/retrieve_all', $params);
    }

    // ═══════════════════════════════════════════════════════
    //  PURCHASE
    // ═══════════════════════════════════════════════════════

    /**
     * Purchase an SMS number.
     * POST /purchase/sms
     *
     * Required: country (ID), service (ID), key
     * Optional: pool
     *
     * Returns: { success, number, order_id, country, service, pool, expires_in, message }
     */
    public function purchaseSms(int $countryId, int $serviceId, ?int $pool = null): array
    {
        $params = [
            'country' => $countryId,
            'service' => $serviceId,
        ];

        if ($pool !== null) {
            $params['pool'] = $pool;
        }

        $result = $this->post('/purchase/sms', $params);

        if (empty($result['success']) || $result['success'] != 1) {
            $message = $result['message'] ?? 'Unknown SMSPool purchase error';
            throw new \Exception("SMSPool purchase failed: {$message}");
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════
    //  ORDER MANAGEMENT
    // ═══════════════════════════════════════════════════════

    /**
     * Check order status and get SMS.
     * POST /sms/check
     *
     * Returns: { status, sms, full_sms, code, phonenumber, order_id, time_left, expiration }
     *
     * Status codes:
     *   1 = Pending, 2 = Expired, 3 = Completed, 4 = Resend,
     *   5 = Cancelled, 6 = Refunded, 7 = Processing, 8 = Activating
     */
    public function checkSms(string $orderId): array
    {
        return $this->post('/sms/check', [
            'orderid' => $orderId,
        ]);
    }

    /**
     * Cancel an order.
     * POST /sms/cancel
     *
     * Returns: { success: 1|0 }
     */
    public function cancelOrder(string $orderId): array
    {
        return $this->post('/sms/cancel', [
            'orderid' => $orderId,
        ]);
    }

    /**
     * Resend SMS for an order.
     * POST /sms/resend
     */
    public function resendSms(string $orderId): array
    {
        return $this->post('/sms/resend', [
            'orderid' => $orderId,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════

    /**
     * Map ISO country code (e.g. "US") to SMSPool country ID.
     * Returns null if no mapping exists.
     */
    public static function mapCountryCode(string $isoCode): ?int
    {
        return self::COUNTRY_MAP[strtoupper($isoCode)] ?? null;
    }

    /**
     * Map service name/slug to SMSPool service ID.
     * Returns null if no mapping exists.
     */
    public static function mapServiceToId(string $serviceName): ?int
    {
        $name = strtolower(trim($serviceName));

        // Direct match
        if (isset(self::SERVICE_MAP[$name])) {
            return self::SERVICE_MAP[$name];
        }

        // Partial match
        foreach (self::SERVICE_MAP as $key => $id) {
            if (str_contains($name, $key)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Map SMSPool status code to a human-readable status string.
     */
    public static function mapStatusCode(int $code): string
    {
        return match ($code) {
            1 => 'PENDING',
            2 => 'EXPIRED',
            3 => 'COMPLETED',
            4 => 'RESEND',
            5 => 'CANCELLED',
            6 => 'REFUNDED',
            7 => 'PROCESSING',
            8 => 'ACTIVATING',
            default => 'UNKNOWN',
        };
    }

    /**
     * Check if a status code is terminal (no further changes expected).
     */
    public static function isTerminalStatus(int $code): bool
    {
        return in_array($code, [2, 3, 5, 6]); // Expired, Completed, Cancelled, Refunded
    }
}
