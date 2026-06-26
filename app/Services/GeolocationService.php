<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeolocationService
{
    private const COUNTRY_CURRENCY = [
        'NG' => ['currency' => 'NGN', 'symbol' => '₦', 'name' => 'Nigerian Naira'],
        'GH' => ['currency' => 'GHS', 'symbol' => '₵', 'name' => 'Ghanaian Cedi'],
        'GB' => ['currency' => 'GBP', 'symbol' => '£', 'name' => 'British Pound'],
        'US' => ['currency' => 'USD', 'symbol' => '$', 'name' => 'US Dollar'],
        'CA' => ['currency' => 'CAD', 'symbol' => 'CA$', 'name' => 'Canadian Dollar'],
        'AU' => ['currency' => 'AUD', 'symbol' => 'A$', 'name' => 'Australian Dollar'],
        'KE' => ['currency' => 'KES', 'symbol' => 'KSh', 'name' => 'Kenyan Shilling'],
        'ZA' => ['currency' => 'ZAR', 'symbol' => 'R', 'name' => 'South African Rand'],
        'TZ' => ['currency' => 'TZS', 'symbol' => 'TSh', 'name' => 'Tanzanian Shilling'],
        'UG' => ['currency' => 'UGX', 'symbol' => 'USh', 'name' => 'Ugandan Shilling'],
        'RW' => ['currency' => 'RWF', 'symbol' => 'RF', 'name' => 'Rwandan Franc'],
        'ET' => ['currency' => 'ETB', 'symbol' => 'Br', 'name' => 'Ethiopian Birr'],
        'EG' => ['currency' => 'EGP', 'symbol' => 'E£', 'name' => 'Egyptian Pound'],
        'DE' => ['currency' => 'EUR', 'symbol' => '€', 'name' => 'Euro'],
        'FR' => ['currency' => 'EUR', 'symbol' => '€', 'name' => 'Euro'],
        'IT' => ['currency' => 'EUR', 'symbol' => '€', 'name' => 'Euro'],
        'ES' => ['currency' => 'EUR', 'symbol' => '€', 'name' => 'Euro'],
        'NL' => ['currency' => 'EUR', 'symbol' => '€', 'name' => 'Euro'],
        'BE' => ['currency' => 'EUR', 'symbol' => '€', 'name' => 'Euro'],
        'PT' => ['currency' => 'EUR', 'symbol' => '€', 'name' => 'Euro'],
        'IE' => ['currency' => 'EUR', 'symbol' => '€', 'name' => 'Euro'],
        'IN' => ['currency' => 'INR', 'symbol' => '₹', 'name' => 'Indian Rupee'],
        'PK' => ['currency' => 'PKR', 'symbol' => '₨', 'name' => 'Pakistani Rupee'],
        'BD' => ['currency' => 'BDT', 'symbol' => '৳', 'name' => 'Bangladeshi Taka'],
        'BR' => ['currency' => 'BRL', 'symbol' => 'R$', 'name' => 'Brazilian Real'],
        'MX' => ['currency' => 'MXN', 'symbol' => 'MX$', 'name' => 'Mexican Peso'],
        'PH' => ['currency' => 'PHP', 'symbol' => '₱', 'name' => 'Philippine Peso'],
        'ID' => ['currency' => 'IDR', 'symbol' => 'Rp', 'name' => 'Indonesian Rupiah'],
        'SG' => ['currency' => 'SGD', 'symbol' => 'S$', 'name' => 'Singapore Dollar'],
        'MY' => ['currency' => 'MYR', 'symbol' => 'RM', 'name' => 'Malaysian Ringgit'],
        'TH' => ['currency' => 'THB', 'symbol' => '฿', 'name' => 'Thai Baht'],
        'VN' => ['currency' => 'VND', 'symbol' => '₫', 'name' => 'Vietnamese Dong'],
        'JP' => ['currency' => 'JPY', 'symbol' => '¥', 'name' => 'Japanese Yen'],
        'CN' => ['currency' => 'CNY', 'symbol' => '¥', 'name' => 'Chinese Yuan'],
        'KR' => ['currency' => 'KRW', 'symbol' => '₩', 'name' => 'South Korean Won'],
        'AE' => ['currency' => 'AED', 'symbol' => 'AED', 'name' => 'UAE Dirham'],
        'SA' => ['currency' => 'SAR', 'symbol' => 'SR', 'name' => 'Saudi Riyal'],
        'TR' => ['currency' => 'TRY', 'symbol' => '₺', 'name' => 'Turkish Lira'],
        'RU' => ['currency' => 'RUB', 'symbol' => '₽', 'name' => 'Russian Ruble'],
        'UA' => ['currency' => 'UAH', 'symbol' => '₴', 'name' => 'Ukrainian Hryvnia'],
        'PL' => ['currency' => 'PLN', 'symbol' => 'zł', 'name' => 'Polish Zloty'],
        'SE' => ['currency' => 'SEK', 'symbol' => 'kr', 'name' => 'Swedish Krona'],
        'NO' => ['currency' => 'NOK', 'symbol' => 'kr', 'name' => 'Norwegian Krone'],
        'DK' => ['currency' => 'DKK', 'symbol' => 'kr', 'name' => 'Danish Krone'],
        'CH' => ['currency' => 'CHF', 'symbol' => 'Fr', 'name' => 'Swiss Franc'],
        'NZ' => ['currency' => 'NZD', 'symbol' => 'NZ$', 'name' => 'New Zealand Dollar'],
        'CI' => ['currency' => 'XOF', 'symbol' => 'Fr', 'name' => 'West African CFA'],
        'SN' => ['currency' => 'XOF', 'symbol' => 'Fr', 'name' => 'West African CFA'],
        'ML' => ['currency' => 'XOF', 'symbol' => 'Fr', 'name' => 'West African CFA'],
        'BF' => ['currency' => 'XOF', 'symbol' => 'Fr', 'name' => 'West African CFA'],
        'TG' => ['currency' => 'XOF', 'symbol' => 'Fr', 'name' => 'West African CFA'],
        'BJ' => ['currency' => 'XOF', 'symbol' => 'Fr', 'name' => 'West African CFA'],
        'NE' => ['currency' => 'XOF', 'symbol' => 'Fr', 'name' => 'West African CFA'],
        'GN' => ['currency' => 'GNF', 'symbol' => 'Fr', 'name' => 'Guinean Franc'],
        'CM' => ['currency' => 'XAF', 'symbol' => 'Fr', 'name' => 'Central African CFA'],
        'ZW' => ['currency' => 'ZWL', 'symbol' => 'Z$', 'name' => 'Zimbabwean Dollar'],
        'ZM' => ['currency' => 'ZMW', 'symbol' => 'ZK', 'name' => 'Zambian Kwacha'],
        'MW' => ['currency' => 'MWK', 'symbol' => 'MK', 'name' => 'Malawian Kwacha'],
        'MZ' => ['currency' => 'MZN', 'symbol' => 'MT', 'name' => 'Mozambican Metical'],
        'BW' => ['currency' => 'BWP', 'symbol' => 'P', 'name' => 'Botswanan Pula'],
        'NA' => ['currency' => 'NAD', 'symbol' => 'N$', 'name' => 'Namibian Dollar'],
        'LR' => ['currency' => 'LRD', 'symbol' => 'L$', 'name' => 'Liberian Dollar'],
        'SL' => ['currency' => 'SLL', 'symbol' => 'Le', 'name' => 'Sierra Leonean Leone'],
        'GM' => ['currency' => 'GMD', 'symbol' => 'D', 'name' => 'Gambian Dalasi'],
    ];

    public function resolveByCountryCode(string $code): array
    {
        $info = self::COUNTRY_CURRENCY[$code] ?? null;
        if ($info) {
            return [...$info, 'country' => $code, 'country_code' => $code];
        }
        return $this->defaultCurrency();
    }

    public function detect(Request $request): array
    {
        $ip = $this->getClientIp($request);

        if ($this->isLocalIp($ip)) {
            return $this->defaultCurrency();
        }

        return Cache::remember("geo_currency_{$ip}", 86400, function () use ($ip) {
            try {
                $res = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,countryCode,country',
                ]);

                if ($res->successful() && $res->json('status') === 'success') {
                    $code = $res->json('countryCode');
                    $info = self::COUNTRY_CURRENCY[$code] ?? null;

                    if ($info) {
                        return array_merge($info, [
                            'country'      => $res->json('country'),
                            'country_code' => $code,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Geolocation failed for IP {$ip}: " . $e->getMessage());
            }

            return $this->defaultCurrency();
        });
    }

    private function getClientIp(Request $request): string
    {
        return $request->header('CF-Connecting-IP')
            ?? $request->header('X-Real-IP')
            ?? (explode(',', $request->header('X-Forwarded-For', ''))[0] ?: null)
            ?? $request->ip();
    }

    private function isLocalIp(string $ip): bool
    {
        return \in_array($ip, ['127.0.0.1', '::1'])
            || str_starts_with($ip, '192.168.')
            || str_starts_with($ip, '10.')
            || str_starts_with($ip, '172.');
    }

    private function defaultCurrency(): array
    {
        return [
            'currency'     => 'USD',
            'symbol'       => '$',
            'name'         => 'US Dollar',
            'country'      => 'Unknown',
            'country_code' => 'US',
        ];
    }
}
