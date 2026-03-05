<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['name' => 'United States',    'code' => 'US', 'flag' => '🇺🇸', 'dial_code' => '+1',   'twilio_code' => 'US', 'success_rate' => 98, 'price_usd' => 1.15],
            ['name' => 'United Kingdom',   'code' => 'GB', 'flag' => '🇬🇧', 'dial_code' => '+44',  'twilio_code' => 'GB', 'success_rate' => 96, 'price_usd' => 1.15],
            ['name' => 'Canada',           'code' => 'CA', 'flag' => '🇨🇦', 'dial_code' => '+1',   'twilio_code' => 'CA', 'success_rate' => 95, 'price_usd' => 1.15],
            ['name' => 'Australia',        'code' => 'AU', 'flag' => '🇦🇺', 'dial_code' => '+61',  'twilio_code' => 'AU', 'success_rate' => 94, 'price_usd' => 0.80],
            ['name' => 'Germany',          'code' => 'DE', 'flag' => '🇩🇪', 'dial_code' => '+49',  'twilio_code' => 'DE', 'success_rate' => 93, 'price_usd' => 0.80],
            ['name' => 'France',           'code' => 'FR', 'flag' => '🇫🇷', 'dial_code' => '+33',  'twilio_code' => 'FR', 'success_rate' => 92, 'price_usd' => 0.80],
            ['name' => 'Netherlands',      'code' => 'NL', 'flag' => '🇳🇱', 'dial_code' => '+31',  'twilio_code' => 'NL', 'success_rate' => 91, 'price_usd' => 0.80],
            ['name' => 'Sweden',           'code' => 'SE', 'flag' => '🇸🇪', 'dial_code' => '+46',  'twilio_code' => 'SE', 'success_rate' => 93, 'price_usd' => 0.80],
            ['name' => 'Poland',           'code' => 'PL', 'flag' => '🇵🇱', 'dial_code' => '+48',  'twilio_code' => 'PL', 'success_rate' => 88, 'price_usd' => 0.35],
            ['name' => 'India',            'code' => 'IN', 'flag' => '🇮🇳', 'dial_code' => '+91',  'twilio_code' => 'IN', 'success_rate' => 88, 'price_usd' => 0.60],
        ];

        foreach ($countries as $country) {
            Country::updateOrCreate(['code' => $country['code']], array_merge($country, ['is_active' => true]));
        }
    }
}
