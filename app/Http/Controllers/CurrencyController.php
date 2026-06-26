<?php

namespace App\Http\Controllers;

use App\Services\ExchangeRateService;
use App\Services\GeolocationService;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    public function __construct(
        private ExchangeRateService $rates,
        private GeolocationService $geo
    ) {}

    public function detect(Request $request)
    {
        // Browser sends its locale region code (e.g. "GB") as a hint — more reliable than IP in dev
        $hint = strtoupper(trim($request->query('hint', '')));
        $geoInfo = $hint
            ? $this->geo->resolveByCountryCode($hint)
            : $this->geo->detect($request);

        $currency    = $geoInfo['currency'];
        $rates       = $this->rates->getRatesFromUSD();
        $rateFromUsd = (float) ($rates[$currency] ?? 1.0);
        $ngnRate     = (float) ($rates['NGN'] ?? 1500.0);
        $rateFromNgn = $rateFromUsd / $ngnRate;

        return response()->json([
            'currency'      => $currency,
            'symbol'        => $geoInfo['symbol'],
            'name'          => $geoInfo['name'],
            'country'       => $geoInfo['country'],
            'country_code'  => $geoInfo['country_code'],
            'rate_from_usd' => round($rateFromUsd, 6),
            'rate_from_ngn' => round($rateFromNgn, 8),
        ]);
    }
}
