<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiProvider;
use App\Models\ApiSetting;
use Illuminate\Http\Request;

class ApiSettingsController extends Controller
{
    /* ══════════════════════════════════════════════
       PROVIDER CRUD
       ══════════════════════════════════════════════ */

    /**
     * List all configured providers (credentials masked).
     */
    public function providers()
    {
        $providers = ApiProvider::orderBy('priority')->orderBy('name')->get()->map(function ($p) {
            $masked = [];
            foreach ($p->credentials as $key => $val) {
                $masked[$key] = [
                    'value'  => $this->mask($val),
                    'is_set' => !empty($val),
                ];
            }

            return [
                'id'            => $p->id,
                'name'          => $p->name,
                'slug'          => $p->slug,
                'type'          => $p->type,
                'description'   => $p->description,
                'is_active'     => $p->is_active,
                'is_configured' => $p->isConfigured(),
                'capabilities'  => $p->capabilities ?? [],
                'credentials'   => $masked,
                'settings'      => $p->settings ?? [],
                'created_at'    => $p->created_at,
                // Routing fields
                'priority'        => $p->priority ?? 10,
                'success_rate'    => (float) ($p->success_rate ?? 100),
                'avg_response_ms' => (int) ($p->avg_response_ms ?? 0),
                'total_requests'  => (int) ($p->total_requests ?? 0),
                'total_successes' => (int) ($p->total_successes ?? 0),
                'total_failures'  => (int) ($p->total_failures ?? 0),
                'cost_multiplier' => (float) ($p->cost_multiplier ?? 1.00),
            ];
        });

        return response()->json($providers);
    }

    /**
     * Get available provider types that can be added.
     */
    public function availableTypes()
    {
        return response()->json(ApiProvider::availableTypes());
    }

    /**
     * Get credential/setting field definitions for a provider type.
     */
    public function providerFields(string $type)
    {
        return response()->json([
            'credential_fields' => ApiProvider::credentialFields($type),
            'setting_fields'    => ApiProvider::settingFields($type),
        ]);
    }

    /**
     * Add a new provider.
     */
    public function storeProvider(Request $request)
    {
        $request->validate([
            'type'        => 'required|string|in:twilio,telnyx,plivo,vonage,5sim,smspva,sms_activate',
            'name'        => 'required|string|max:100',
            'credentials' => 'required|array',
            'settings'    => 'nullable|array',
        ]);

        // Generate unique slug
        $baseSlug = strtolower($request->type);
        $slug = $baseSlug;
        $counter = 1;
        while (ApiProvider::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . (++$counter);
        }

        // Resolve capabilities from type definition
        $typeDef = collect(ApiProvider::availableTypes())->firstWhere('type', $request->type);

        // Determine priority: next in line
        $maxPriority = ApiProvider::max('priority') ?? 0;

        $provider = ApiProvider::create([
            'name'         => $request->name,
            'slug'         => $slug,
            'type'         => $request->type,
            'credentials'  => $request->credentials,
            'settings'     => $request->settings ?? [],
            'description'  => $typeDef['description'] ?? '',
            'is_active'    => true,
            'capabilities' => $typeDef['capabilities'] ?? ['countries', 'numbers', 'pricing'],
            'priority'     => $maxPriority + 1,
        ]);

        return response()->json([
            'message'  => "Provider '{$provider->name}' added successfully.",
            'provider' => [
                'id'            => $provider->id,
                'name'          => $provider->name,
                'slug'          => $provider->slug,
                'type'          => $provider->type,
                'is_configured' => $provider->isConfigured(),
                'priority'      => $provider->priority,
            ],
        ], 201);
    }

    /**
     * Update provider credentials/settings.
     */
    public function updateProvider(Request $request, ApiProvider $provider)
    {
        $request->validate([
            'name'            => 'sometimes|string|max:100',
            'credentials'     => 'sometimes|array',
            'settings'        => 'sometimes|array',
            'is_active'       => 'sometimes|boolean',
            'priority'        => 'sometimes|integer|min:1|max:999',
            'cost_multiplier' => 'sometimes|numeric|min:0.01|max:99.99',
        ]);

        if ($request->has('name')) {
            $provider->name = $request->name;
        }

        if ($request->has('credentials')) {
            // Merge: only overwrite non-empty values
            $existing = $provider->credentials;
            foreach ($request->credentials as $key => $val) {
                if ($val !== null && $val !== '') {
                    $existing[$key] = $val;
                }
            }
            $provider->credentials = $existing;
        }

        if ($request->has('settings')) {
            $existing = $provider->settings ?? [];
            foreach ($request->settings as $key => $val) {
                if ($val !== null && $val !== '') {
                    $existing[$key] = $val;
                }
            }
            $provider->settings = $existing;
        }

        if ($request->has('is_active')) {
            $provider->is_active = $request->is_active;
        }

        if ($request->has('priority')) {
            $provider->priority = $request->priority;
        }

        if ($request->has('cost_multiplier')) {
            $provider->cost_multiplier = $request->cost_multiplier;
        }

        $provider->save();

        return response()->json([
            'message' => "Provider '{$provider->name}' updated.",
        ]);
    }

    /**
     * Delete a provider.
     */
    public function destroyProvider(ApiProvider $provider)
    {
        $name = $provider->name;
        $provider->delete();

        return response()->json([
            'message' => "Provider '{$name}' deleted.",
        ]);
    }

    /**
     * Toggle provider active status.
     */
    public function toggleProvider(ApiProvider $provider)
    {
        $provider->update(['is_active' => !$provider->is_active]);

        return response()->json([
            'message'   => $provider->is_active ? "Provider '{$provider->name}' activated." : "Provider '{$provider->name}' deactivated.",
            'is_active' => $provider->is_active,
        ]);
    }

    /**
     * Reset routing metrics for a provider.
     */
    public function resetProviderMetrics(ApiProvider $provider)
    {
        $provider->resetMetrics();

        return response()->json([
            'message' => "Routing metrics for '{$provider->name}' have been reset.",
        ]);
    }

    /* ══════════════════════════════════════════════
       ROUTING CONFIG
       ══════════════════════════════════════════════ */

    /**
     * Return routing configuration.
     */
    public function routingConfig()
    {
        return response()->json([
            'routing_mode' => ApiSetting::getValue('routing_mode', 'priority'),
            'providers'    => ApiProvider::where('is_active', true)
                ->orderBy('priority')
                ->get(['id', 'name', 'slug', 'type', 'priority', 'success_rate', 'avg_response_ms', 'total_requests', 'total_successes', 'total_failures', 'cost_multiplier']),
        ]);
    }

    /**
     * Update routing configuration.
     */
    public function updateRouting(Request $request)
    {
        $request->validate([
            'routing_mode' => 'required|string|in:priority,cheapest,smart',
        ]);

        ApiSetting::setValue('routing_mode', $request->routing_mode);

        return response()->json([
            'message' => 'Routing mode updated to: ' . $request->routing_mode,
        ]);
    }

    /**
     * Bulk-update provider priorities.
     */
    public function updatePriorities(Request $request)
    {
        $request->validate([
            'priorities'        => 'required|array',
            'priorities.*.id'   => 'required|exists:api_providers,id',
            'priorities.*.priority' => 'required|integer|min:1|max:999',
        ]);

        foreach ($request->priorities as $item) {
            ApiProvider::where('id', $item['id'])->update(['priority' => $item['priority']]);
        }

        return response()->json([
            'message' => 'Provider priorities updated.',
        ]);
    }

    /* ══════════════════════════════════════════════
       PRICING CONFIG (global settings)
       ══════════════════════════════════════════════ */

    /**
     * Return pricing config.
     */
    public function pricingConfig()
    {
        return response()->json([
            'usd_to_ngn_rate'        => (float) ApiSetting::getValue('usd_to_ngn_rate', 1500),
            'pricing_markup_percent' => (float) ApiSetting::getValue('pricing_markup_percent', 0),
        ]);
    }

    /**
     * Update pricing config.
     */
    public function updatePricing(Request $request)
    {
        $request->validate([
            'usd_to_ngn_rate'        => 'required|numeric|min:1|max:100000',
            'pricing_markup_percent' => 'required|numeric|min:0|max:1000',
        ]);

        ApiSetting::setValue('usd_to_ngn_rate', $request->usd_to_ngn_rate);
        ApiSetting::setValue('pricing_markup_percent', $request->pricing_markup_percent);

        return response()->json([
            'message' => 'Pricing config updated successfully.',
        ]);
    }

    /* ── Old index/update kept for backward compat (settings tab) ── */

    public function index()
    {
        $settings = [];
        $settings['usd_to_ngn_rate'] = [
            'value'  => (float) ApiSetting::getValue('usd_to_ngn_rate', 1500),
            'is_set' => true,
        ];
        $settings['pricing_markup_percent'] = [
            'value'  => (float) ApiSetting::getValue('pricing_markup_percent', 0),
            'is_set' => true,
        ];

        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $request->validate([
            'usd_to_ngn_rate'        => 'nullable|numeric|min:1|max:100000',
            'pricing_markup_percent' => 'nullable|numeric|min:0|max:1000',
        ]);

        $updated = 0;
        foreach (['usd_to_ngn_rate', 'pricing_markup_percent'] as $key) {
            $val = $request->input($key);
            if ($val !== null && $val !== '') {
                ApiSetting::setValue($key, $val);
                $updated++;
            }
        }

        return response()->json([
            'message' => "{$updated} setting(s) updated successfully.",
        ]);
    }

    private function mask(string $value): string
    {
        if (strlen($value) <= 6) return '••••••';
        return substr($value, 0, 3) . str_repeat('•', max(0, strlen($value) - 6)) . substr($value, -3);
    }
}
