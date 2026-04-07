<?php

namespace App\Http\Controllers;

use App\Models\Country;

class CountryController extends Controller
{
    public function index()
    {
        $popularCodes = ['US', 'GB', 'CA'];

        $countries = Country::where('is_active', true)
            ->withCount('orders')
            ->orderByDesc('orders_count')
            ->orderByDesc('success_rate')
            ->orderBy('name')
            ->get()
            ->map(function ($country) use ($popularCodes) {
                $available = (int) ($country->available_numbers ?? 200);

                // Use the direct NGN price field; fall back to USD conversion only as legacy
                $price = 0.0;
                if ($country->price && (float) $country->price > 0) {
                    $price = (float) $country->price;
                } elseif ($country->price_usd && (float) $country->price_usd > 0) {
                    $price = round((float) $country->price_usd * 1600, 0);
                }

                return [
                    'id'                => $country->id,
                    'name'              => $country->name,
                    'code'              => $country->code,
                    'flag'              => $country->flag ?? '🌍',
                    'dial_code'         => $country->dial_code,
                    'twilio_code'       => $country->twilio_code,
                    'price'             => $price,
                    'available_numbers' => $available,
                    'is_low_stock'      => $available > 0 && $available <= 10,
                    'success_rate'      => (float) ($country->success_rate ?? 95),
                    'order_count'       => $country->orders_count ?? 0,
                    'is_most_used'      => in_array($country->code, $popularCodes),
                ];
            });

        return response()->json($countries);
    }
}
