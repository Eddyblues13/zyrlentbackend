<?php

namespace App\Http\Controllers\Admin;

use App\Models\Country;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class CountryManagerController extends Controller
{
    /**
     * List all countries — paginated with search.
     */
    public function index(Request $request)
    {
        $query = Country::query();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('dial_code', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->orderBy('name')->paginate($request->get('per_page', 50))
        );
    }

    /**
     * Create a new country.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|unique:countries,name',
            'code'        => 'required|string|size:2|unique:countries,code',
            'flag'        => 'nullable|string',
            'dial_code'   => 'required|string',
            'twilio_code' => 'required|string|size:2',
            'price'       => 'required|numeric|min:0',
            'price_usd'   => 'nullable|numeric|min:0',
            'is_active'   => 'boolean',
        ]);

        $validated['is_active'] = $validated['is_active'] ?? true;
        $country = Country::create($validated);

        return response()->json([
            'message' => 'Country added successfully.',
            'country' => $country,
        ], 201);
    }

    /**
     * Update a country.
     */
    public function update(Request $request, Country $country)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|unique:countries,name,' . $country->id,
            'code'        => 'sometimes|string|size:2',
            'flag'        => 'nullable|string',
            'dial_code'   => 'sometimes|string',
            'twilio_code' => 'sometimes|string|size:2',
            'price'       => 'sometimes|numeric|min:0',
            'price_usd'   => 'sometimes|numeric|min:0',
            'is_active'   => 'boolean',
        ]);

        $country->update($validated);

        return response()->json([
            'message' => 'Country updated.',
            'country' => $country->fresh(),
        ]);
    }

    /**
     * Toggle active status.
     */
    public function toggleActive(Country $country)
    {
        $country->update(['is_active' => !$country->is_active]);

        return response()->json([
            'message' => $country->is_active ? 'Country activated.' : 'Country deactivated.',
            'country' => $country,
        ]);
    }

    /**
     * Delete a country.
     */
    public function destroy(Country $country)
    {
        if ($country->orders()->exists()) {
            return response()->json(['message' => 'Cannot delete: country has existing orders.'], 422);
        }

        $country->delete();
        return response()->json(['message' => 'Country deleted.']);
    }

    /**
     * Bulk adjust all country prices by a percentage.
     * positive = increase, negative = decrease
     */
    public function bulkAdjustPrices(Request $request)
    {
        $request->validate([
            'percentage' => 'required|numeric|min:-90|max:1000',
        ]);

        $percent = (float) $request->percentage;
        $multiplier = 1 + ($percent / 100);

        $countries = Country::all();
        $updated = 0;

        foreach ($countries as $country) {
            $oldPrice = (float) $country->price;
            if ($oldPrice > 0) {
                $country->price = round($oldPrice * $multiplier, 2);
                $country->save();
                $updated++;
            }
        }

        return response()->json([
            'message' => "Adjusted {$updated} country prices by {$percent}%.",
            'updated' => $updated,
        ]);
    }

    /**
     * Suggest common countries with Twilio support.
     */
    public function fetchSuggestions()
    {
        $suggestions = [
            ['name' => 'United States',  'code' => 'US', 'flag' => '🇺🇸', 'dial_code' => '+1',   'twilio_code' => 'US', 'price_usd' => 1.00, 'price' => 1600],
            ['name' => 'United Kingdom', 'code' => 'GB', 'flag' => '🇬🇧', 'dial_code' => '+44',  'twilio_code' => 'GB', 'price_usd' => 1.50, 'price' => 2400],
            ['name' => 'Canada',         'code' => 'CA', 'flag' => '🇨🇦', 'dial_code' => '+1',   'twilio_code' => 'CA', 'price_usd' => 1.00, 'price' => 1600],
            ['name' => 'Germany',        'code' => 'DE', 'flag' => '��🇪', 'dial_code' => '+49',  'twilio_code' => 'DE', 'price_usd' => 2.00, 'price' => 3200],
            ['name' => 'France',         'code' => 'FR', 'flag' => '🇫🇷', 'dial_code' => '+33',  'twilio_code' => 'FR', 'price_usd' => 2.00, 'price' => 3200],
            ['name' => 'Netherlands',    'code' => 'NL', 'flag' => '🇳🇱', 'dial_code' => '+31',  'twilio_code' => 'NL', 'price_usd' => 2.00, 'price' => 3200],
            ['name' => 'Sweden',         'code' => 'SE', 'flag' => '🇸🇪', 'dial_code' => '+46',  'twilio_code' => 'SE', 'price_usd' => 2.00, 'price' => 3200],
            ['name' => 'Australia',      'code' => 'AU', 'flag' => '🇦🇺', 'dial_code' => '+61',  'twilio_code' => 'AU', 'price_usd' => 1.50, 'price' => 2400],
            ['name' => 'India',          'code' => 'IN', 'flag' => '��🇳', 'dial_code' => '+91',  'twilio_code' => 'IN', 'price_usd' => 0.50, 'price' => 800],
            ['name' => 'Brazil',         'code' => 'BR', 'flag' => '🇧🇷', 'dial_code' => '+55',  'twilio_code' => 'BR', 'price_usd' => 1.50, 'price' => 2400],
            ['name' => 'Nigeria',        'code' => 'NG', 'flag' => '🇳🇬', 'dial_code' => '+234', 'twilio_code' => 'NG', 'price_usd' => 0.50, 'price' => 800],
            ['name' => 'South Africa',   'code' => 'ZA', 'flag' => '🇿🇦', 'dial_code' => '+27',  'twilio_code' => 'ZA', 'price_usd' => 1.00, 'price' => 1600],
            ['name' => 'Japan',          'code' => 'JP', 'flag' => '🇯🇵', 'dial_code' => '+81',  'twilio_code' => 'JP', 'price_usd' => 3.00, 'price' => 4800],
            ['name' => 'South Korea',    'code' => 'KR', 'flag' => '🇰��', 'dial_code' => '+82',  'twilio_code' => 'KR', 'price_usd' => 3.00, 'price' => 4800],
            ['name' => 'Spain',          'code' => 'ES', 'flag' => '🇪🇸', 'dial_code' => '+34',  'twilio_code' => 'ES', 'price_usd' => 2.00, 'price' => 3200],
            ['name' => 'Italy',          'code' => 'IT', 'flag' => '🇮🇹', 'dial_code' => '+39',  'twilio_code' => 'IT', 'price_usd' => 2.00, 'price' => 3200],
            ['name' => 'Mexico',         'code' => 'MX', 'flag' => '🇲🇽', 'dial_code' => '+52',  'twilio_code' => 'MX', 'price_usd' => 1.00, 'price' => 1600],
            ['name' => 'Poland',         'code' => 'PL', 'flag' => '🇵🇱', 'dial_code' => '+48',  'twilio_code' => 'PL', 'price_usd' => 1.50, 'price' => 2400],
            ['name' => 'Indonesia',      'code' => 'ID', 'flag' => '🇮��', 'dial_code' => '+62',  'twilio_code' => 'ID', 'price_usd' => 0.80, 'price' => 1280],
            ['name' => 'Philippines',    'code' => 'PH', 'flag' => '🇵🇭', 'dial_code' => '+63',  'twilio_code' => 'PH', 'price_usd' => 0.80, 'price' => 1280],
        ];

        $existingCodes = Country::pluck('code')->map(fn($c) => strtoupper($c))->toArray();
        $filtered = array_values(array_filter($suggestions, fn($s) => !in_array(strtoupper($s['code']), $existingCodes)));

        return response()->json($filtered);
    }

    /**
     * Import selected countries from suggestions.
     */
    public function import(Request $request)
    {
        $validated = $request->validate([
            'countries'                => 'required|array|min:1',
            'countries.*.name'         => 'required|string',
            'countries.*.code'         => 'required|string|size:2',
            'countries.*.flag'         => 'nullable|string',
            'countries.*.dial_code'    => 'required|string',
            'countries.*.twilio_code'  => 'required|string|size:2',
            'countries.*.price_usd'    => 'nullable|numeric|min:0',
            'countries.*.price'        => 'nullable|numeric|min:0',
        ]);

        $imported = 0;
        foreach ($validated['countries'] as $data) {
            $exists = Country::where('code', $data['code'])->exists();
            if (!$exists) {
                Country::create(array_merge($data, ['is_active' => true]));
                $imported++;
            }
        }

        return response()->json([
            'message' => "{$imported} countries imported successfully.",
        ]);
    }
}
