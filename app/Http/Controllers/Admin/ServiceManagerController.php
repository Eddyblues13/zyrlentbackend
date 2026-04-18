<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceManagerController extends Controller
{
    public function index()
    {
        $services = Service::orderBy('name')->get();
        return response()->json($services);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:services,name',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
            'category' => 'nullable|string|max:50',
            'cost' => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $service = Service::create($validated);

        return response()->json([
            'message' => 'Service created successfully.',
            'service' => $service,
        ], 201);
    }

    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
            'category' => 'nullable|string|max:50',
            'cost' => 'sometimes|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $service->update($validated);

        return response()->json([
            'message' => 'Service updated.',
            'service' => $service,
        ]);
    }

    public function toggleActive(Service $service)
    {
        $service->update(['is_active' => !$service->is_active]);

        return response()->json([
            'message' => $service->is_active ? 'Service activated.' : 'Service deactivated.',
            'service' => $service,
        ]);
    }

    public function destroy(Service $service)
    {
        $name = $service->name;
        $service->delete();

        return response()->json(['message' => "{$name} deleted."]);
    }

    /**
     * Bulk adjust all service prices by a percentage.
     * positive = increase, negative = decrease
     */
    public function bulkAdjustPrices(Request $request)
    {
        $request->validate([
            'percentage' => 'required|numeric|min:-90|max:1000',
        ]);

        $percent = (float) $request->percentage;
        $multiplier = 1 + ($percent / 100);

        $services = Service::all();
        $updated = 0;

        foreach ($services as $service) {
            $oldCost = (float) $service->cost;
            if ($oldCost > 0) {
                $service->cost = round($oldCost * $multiplier, 2);
                $service->save();
                $updated++;
            }
        }

        return response()->json([
            'message' => "Adjusted {$updated} service prices by {$percent}%.",
            'updated' => $updated,
        ]);
    }

    /**
     * Suggest common OTP verification services.
     */
    public function fetchSuggestions()
    {
        $suggestions = [
            ['name' => 'WhatsApp', 'icon' => 'https://logo.clearbit.com/whatsapp.com', 'color' => '#25D366', 'category' => 'Messaging', 'cost' => 150],
            ['name' => 'Telegram', 'icon' => 'https://logo.clearbit.com/telegram.org', 'color' => '#0088CC', 'category' => 'Messaging', 'cost' => 150],
            ['name' => 'Gmail', 'icon' => 'https://logo.clearbit.com/gmail.com', 'color' => '#EA4335', 'category' => 'Email', 'cost' => 120],
            ['name' => 'Facebook', 'icon' => 'https://logo.clearbit.com/facebook.com', 'color' => '#1877F2', 'category' => 'Social', 'cost' => 150],
            ['name' => 'Instagram', 'icon' => 'https://logo.clearbit.com/instagram.com', 'color' => '#E4405F', 'category' => 'Social', 'cost' => 150],
            ['name' => 'Twitter / X', 'icon' => 'https://logo.clearbit.com/x.com', 'color' => '#1DA1F2', 'category' => 'Social', 'cost' => 180],
            ['name' => 'TikTok', 'icon' => 'https://logo.clearbit.com/tiktok.com', 'color' => '#000000', 'category' => 'Social', 'cost' => 200],
            ['name' => 'Snapchat', 'icon' => 'https://logo.clearbit.com/snapchat.com', 'color' => '#FFFC00', 'category' => 'Social', 'cost' => 180],
            ['name' => 'Discord', 'icon' => 'https://logo.clearbit.com/discord.com', 'color' => '#5865F2', 'category' => 'Gaming', 'cost' => 150],
            ['name' => 'Amazon', 'icon' => 'https://logo.clearbit.com/amazon.com', 'color' => '#FF9900', 'category' => 'Shopping', 'cost' => 200],
            ['name' => 'Uber', 'icon' => 'https://logo.clearbit.com/uber.com', 'color' => '#000000', 'category' => 'Transport', 'cost' => 250],
            ['name' => 'Netflix', 'icon' => 'https://logo.clearbit.com/netflix.com', 'color' => '#E50914', 'category' => 'Entertainment', 'cost' => 200],
            ['name' => 'Spotify', 'icon' => 'https://logo.clearbit.com/spotify.com', 'color' => '#1DB954', 'category' => 'Entertainment', 'cost' => 150],
            ['name' => 'PayPal', 'icon' => 'https://logo.clearbit.com/paypal.com', 'color' => '#003087', 'category' => 'Finance', 'cost' => 300],
            ['name' => 'Binance', 'icon' => 'https://logo.clearbit.com/binance.com', 'color' => '#F0B90B', 'category' => 'Finance', 'cost' => 350],
            ['name' => 'LinkedIn', 'icon' => 'https://logo.clearbit.com/linkedin.com', 'color' => '#0A66C2', 'category' => 'Social', 'cost' => 180],
            ['name' => 'Yahoo Mail', 'icon' => 'https://logo.clearbit.com/yahoo.com', 'color' => '#6001D2', 'category' => 'Email', 'cost' => 120],
            ['name' => 'Microsoft', 'icon' => 'https://logo.clearbit.com/microsoft.com', 'color' => '#00A4EF', 'category' => 'Email', 'cost' => 150],
            ['name' => 'Apple ID', 'icon' => 'https://logo.clearbit.com/apple.com', 'color' => '#A2AAAD', 'category' => 'Other', 'cost' => 250],
            ['name' => 'Steam', 'icon' => 'https://logo.clearbit.com/steampowered.com', 'color' => '#1B2838', 'category' => 'Gaming', 'cost' => 200],
        ];

        $existingNames = Service::pluck('name')->map(fn($n) => strtolower($n))->toArray();
        $filtered = array_values(array_filter($suggestions, fn($s) => !in_array(strtolower($s['name']), $existingNames)));

        return response()->json($filtered);
    }

    /**
     * Bulk import selected suggestions as services.
     */
    public function importSuggestions(Request $request)
    {
        $request->validate([
            'services' => 'required|array|min:1',
            'services.*.name' => 'required|string|max:100',
            'services.*.icon' => 'nullable|string',
            'services.*.color' => 'nullable|string',
            'services.*.category' => 'nullable|string',
            'services.*.cost' => 'required|numeric|min:0',
        ]);

        $created = 0;
        foreach ($request->services as $item) {
            if (! Service::where('name', $item['name'])->exists()) {
                Service::create(array_merge($item, ['is_active' => true]));
                $created++;
            }
        }

        return response()->json([
            'message' => "{$created} services imported successfully.",
            'total' => Service::count(),
        ]);
    }
}
