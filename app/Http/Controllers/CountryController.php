<?php

namespace App\Http\Controllers;

use App\Models\Country;

class CountryController extends Controller
{
    public function index()
    {
        $countries = Country::where('is_active', true)
            ->select('id', 'name', 'code', 'flag', 'dial_code', 'twilio_code', 'success_rate', 'price_usd')
            ->orderByDesc('success_rate')
            ->get();

        return response()->json($countries);
    }
}
