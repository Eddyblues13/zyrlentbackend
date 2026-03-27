<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Support\Facades\Schema;

class CountryController extends Controller
{
    public function index()
    {
        // Detect which optional columns exist in the countries table
        $hasPriceCol      = Schema::hasColumn('countries', 'price');
        $hasAvailableCol  = Schema::hasColumn('countries', 'available_numbers');
        $hasSuccessRate   = Schema::hasColumn('countries', 'success_rate');
        $hasFlag          = Schema::hasColumn('countries', 'flag');
        $hasCode          = Schema::hasColumn('countries', 'code');
        $hasDialCode      = Schema::hasColumn('countries', 'dial_code');
        $hasTwilioCode    = Schema::hasColumn('countries', 'twilio_code');
        $hasPriceUsd      = Schema::hasColumn('countries', 'price_usd');
        $hasOrdersTable   = Schema::hasTable('number_orders');

        // Build a safe column list
        $selectCols = ['id', 'name', 'is_active'];
        if ($hasCode)      $selectCols[] = 'code';
        if ($hasFlag)      $selectCols[] = 'flag';
        if ($hasDialCode)  $selectCols[] = 'dial_code';
        if ($hasTwilioCode) $selectCols[] = 'twilio_code';
        if ($hasPriceUsd)  $selectCols[] = 'price_usd';
        if ($hasSuccessRate) $selectCols[] = 'success_rate';
        if ($hasPriceCol)  $selectCols[] = 'price';
        if ($hasAvailableCol) $selectCols[] = 'available_numbers';

        $query = Country::where('is_active', true)->select($selectCols);

        if ($hasOrdersTable) {
            $query->withCount('orders');
        }
        if ($hasOrdersTable) {
            $query->orderByDesc('orders_count');
        }
        if ($hasSuccessRate) {
            $query->orderByDesc('success_rate');
        }
        $query->orderBy('name');

        // Countries that should always be marked as "Popular"
        $popularCodes = ['US', 'GB', 'CA'];

        $countries = $query->get()->map(function ($country, $index) use (
            $hasOrdersTable, $hasPriceCol, $hasAvailableCol,
            $hasSuccessRate, $hasFlag, $hasCode, $hasDialCode, $hasTwilioCode, $hasPriceUsd,
            $popularCodes
        ) {
            $available = $hasAvailableCol ? (int) ($country->available_numbers ?? 200) : 200;

            $price = 0.0;
            if ($hasPriceCol && $country->price) {
                $price = (float) $country->price;
            } elseif ($hasPriceUsd && $country->price_usd) {
                $price = round((float) $country->price_usd * 1600, 0);
            }

            $countryCode = $hasCode ? $country->code : null;

            return [
                'id'                => $country->id,
                'name'              => $country->name,
                'code'              => $countryCode,
                'flag'              => $hasFlag      ? $country->flag       : '🌍',
                'dial_code'         => $hasDialCode  ? $country->dial_code  : null,
                'twilio_code'       => $hasTwilioCode ? $country->twilio_code : null,
                'price'             => $price,
                'price_usd'         => $hasPriceUsd  ? (float) $country->price_usd : 0.0,
                'available_numbers' => $available,
                'is_low_stock'      => $available > 0 && $available <= 10,
                'success_rate'      => $hasSuccessRate ? (float) ($country->success_rate ?? 95) : 95.0,
                'order_count'       => $hasOrdersTable ? $country->orders_count : 0,
                'is_most_used'      => in_array($countryCode, $popularCodes),
            ];
        });

        return response()->json($countries);
    }
}
