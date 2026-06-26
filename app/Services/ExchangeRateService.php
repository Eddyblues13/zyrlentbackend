<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    private const FALLBACK_RATES = [
        'USD' => 1.0,
        'NGN' => 1500.0,
        'GHS' => 15.5,
        'GBP' => 0.79,
        'EUR' => 0.92,
        'KES' => 130.0,
        'ZAR' => 18.5,
        'CAD' => 1.36,
        'AUD' => 1.53,
        'INR' => 83.0,
        'ZAR' => 18.5,
        'TZS' => 2700.0,
        'UGX' => 3800.0,
        'XOF' => 600.0,
        'XAF' => 600.0,
        'EGP' => 48.0,
    ];

    public function getRatesFromUSD(): array
    {
        return Cache::remember('exchange_rates_usd', 3600, function () {
            try {
                $response = Http::timeout(5)->get('https://api.exchangerate-api.com/v4/latest/USD');
                if ($response->successful()) {
                    $rates = $response->json('rates', []);
                    if (!empty($rates)) {
                        return $rates;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Exchange rate fetch failed: ' . $e->getMessage());
            }

            return self::FALLBACK_RATES;
        });
    }

    public function getRate(string $currency): float
    {
        $rates = $this->getRatesFromUSD();
        return (float) ($rates[strtoupper($currency)] ?? 1.0);
    }
}
