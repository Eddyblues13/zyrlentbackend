<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\Country;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NumberInventoryController extends Controller
{
    /**
     * List phone numbers with filters & search.
     */
    public function index(Request $request)
    {
        $query = PhoneNumber::with(['country:id,name,flag,code', 'services:id,name,icon,color']);

        // Status filter
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Country filter
        if ($countryId = $request->get('country_id')) {
            $query->where('country_id', $countryId);
        }

        // Operator filter
        if ($operator = $request->get('operator')) {
            $query->where('operator', $operator);
        }

        // Provider filter
        if ($provider = $request->get('provider')) {
            $query->where('provider', $provider);
        }

        // Service compatibility filter
        if ($serviceId = $request->get('service_id')) {
            $query->forService($serviceId);
        }

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('phone_number', 'like', "%{$search}%")
                  ->orWhere('operator', 'like', "%{$search}%")
                  ->orWhere('provider', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('country', fn($q2) => $q2->where('name', 'like', "%{$search}%"));
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $allowedSorts = ['phone_number', 'status', 'sell_price', 'cost_price', 'times_used', 'created_at', 'expires_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $numbers = $query->paginate($request->get('per_page', 20));

        return response()->json($numbers);
    }

    /**
     * Get inventory statistics.
     */
    public function stats()
    {
        $stats = [
            'total'     => PhoneNumber::count(),
            'available' => PhoneNumber::available()->count(),
            'in_use'    => PhoneNumber::inUse()->count(),
            'reserved'  => PhoneNumber::reserved()->count(),
            'expired'   => PhoneNumber::expired()->count(),
            'retired'   => PhoneNumber::retired()->count(),
        ];

        // Top countries by available numbers
        $stats['top_countries'] = PhoneNumber::available()
            ->select('country_id', DB::raw('count(*) as count'))
            ->groupBy('country_id')
            ->orderByDesc('count')
            ->limit(5)
            ->with('country:id,name,flag')
            ->get();

        // Provider breakdown
        $stats['providers'] = PhoneNumber::select('provider', DB::raw('count(*) as count'))
            ->groupBy('provider')
            ->orderByDesc('count')
            ->get();

        // Operator breakdown
        $stats['operators'] = PhoneNumber::select('operator', DB::raw('count(*) as count'))
            ->whereNotNull('operator')
            ->where('operator', '!=', '')
            ->groupBy('operator')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json($stats);
    }

    /**
     * Get filter options (unique operators, providers, countries, services).
     */
    public function filterOptions()
    {
        return response()->json([
            'operators' => PhoneNumber::select('operator')
                ->whereNotNull('operator')
                ->where('operator', '!=', '')
                ->distinct()
                ->orderBy('operator')
                ->pluck('operator'),
            'providers' => PhoneNumber::select('provider')
                ->distinct()
                ->orderBy('provider')
                ->pluck('provider'),
            'countries' => Country::select('id', 'name', 'flag', 'code')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'services' => Service::select('id', 'name', 'icon', 'color')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Add a single number to inventory.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => 'required|string|unique:phone_numbers,phone_number',
            'country_id'   => 'required|exists:countries,id',
            'operator'     => 'nullable|string|max:100',
            'provider'     => 'required|string|max:50',
            'provider_sid' => 'nullable|string|max:200',
            'status'       => 'required|in:available,in_use,reserved,expired,retired',
            'cost_price'   => 'required|numeric|min:0',
            'sell_price'   => 'required|numeric|min:0',
            'max_uses'     => 'required|integer|min:1',
            'expires_at'   => 'nullable|date',
            'notes'        => 'nullable|string|max:500',
            'service_ids'  => 'nullable|array',
            'service_ids.*' => 'exists:services,id',
        ]);

        $serviceIds = $validated['service_ids'] ?? [];
        unset($validated['service_ids']);

        $number = PhoneNumber::create($validated);

        if (!empty($serviceIds)) {
            $number->services()->sync($serviceIds);
        }

        $number->load(['country:id,name,flag,code', 'services:id,name,icon,color']);

        return response()->json([
            'message' => 'Number added to inventory.',
            'number'  => $number,
        ], 201);
    }

    /**
     * Bulk import numbers.
     */
    public function import(Request $request)
    {
        $request->validate([
            'numbers'            => 'required|array|min:1|max:500',
            'numbers.*.phone_number' => 'required|string',
            'numbers.*.country_id'   => 'required|exists:countries,id',
            'numbers.*.operator'     => 'nullable|string|max:100',
            'numbers.*.provider'     => 'nullable|string|max:50',
            'numbers.*.provider_sid' => 'nullable|string|max:200',
            'numbers.*.cost_price'   => 'nullable|numeric|min:0',
            'numbers.*.sell_price'   => 'nullable|numeric|min:0',
            'numbers.*.max_uses'     => 'nullable|integer|min:1',
            'numbers.*.notes'        => 'nullable|string|max:500',
        ]);

        // Also accept bulk defaults
        $defaultProvider  = $request->get('default_provider', 'manual');
        $defaultCostPrice = $request->get('default_cost_price', 0);
        $defaultSellPrice = $request->get('default_sell_price', 0);
        $defaultMaxUses   = $request->get('default_max_uses', 1);
        $defaultServiceIds = $request->get('default_service_ids', []);

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        DB::beginTransaction();
        try {
            foreach ($request->numbers as $i => $row) {
                // Skip duplicates
                if (PhoneNumber::where('phone_number', $row['phone_number'])->exists()) {
                    $skipped++;
                    $errors[] = "Row " . ($i + 1) . ": {$row['phone_number']} already exists.";
                    continue;
                }

                $number = PhoneNumber::create([
                    'phone_number' => $row['phone_number'],
                    'country_id'   => $row['country_id'],
                    'operator'     => $row['operator'] ?? null,
                    'provider'     => $row['provider'] ?? $defaultProvider,
                    'provider_sid' => $row['provider_sid'] ?? null,
                    'status'       => 'available',
                    'cost_price'   => $row['cost_price'] ?? $defaultCostPrice,
                    'sell_price'   => $row['sell_price'] ?? $defaultSellPrice,
                    'max_uses'     => $row['max_uses'] ?? $defaultMaxUses,
                    'notes'        => $row['notes'] ?? null,
                ]);

                // Attach services
                $serviceIds = $row['service_ids'] ?? $defaultServiceIds;
                if (!empty($serviceIds)) {
                    $number->services()->sync($serviceIds);
                }

                $imported++;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message'  => "Imported {$imported} numbers. Skipped {$skipped}.",
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
    }

    /**
     * Update a single number.
     */
    public function update(Request $request, PhoneNumber $phoneNumber)
    {
        $validated = $request->validate([
            'phone_number' => 'sometimes|string|unique:phone_numbers,phone_number,' . $phoneNumber->id,
            'country_id'   => 'sometimes|exists:countries,id',
            'operator'     => 'nullable|string|max:100',
            'provider'     => 'sometimes|string|max:50',
            'provider_sid' => 'nullable|string|max:200',
            'status'       => 'sometimes|in:available,in_use,reserved,expired,retired',
            'cost_price'   => 'sometimes|numeric|min:0',
            'sell_price'   => 'sometimes|numeric|min:0',
            'max_uses'     => 'sometimes|integer|min:1',
            'expires_at'   => 'nullable|date',
            'notes'        => 'nullable|string|max:500',
            'service_ids'  => 'nullable|array',
            'service_ids.*' => 'exists:services,id',
        ]);

        $serviceIds = $validated['service_ids'] ?? null;
        unset($validated['service_ids']);

        $phoneNumber->update($validated);

        if ($serviceIds !== null) {
            $phoneNumber->services()->sync($serviceIds);
        }

        $phoneNumber->load(['country:id,name,flag,code', 'services:id,name,icon,color']);

        return response()->json([
            'message' => 'Number updated.',
            'number'  => $phoneNumber,
        ]);
    }

    /**
     * Delete a number from inventory.
     */
    public function destroy(PhoneNumber $phoneNumber)
    {
        if ($phoneNumber->status === 'in_use') {
            return response()->json([
                'message' => 'Cannot delete a number that is currently in use.',
            ], 422);
        }

        $phoneNumber->services()->detach();
        $phoneNumber->delete();

        return response()->json(['message' => 'Number removed from inventory.']);
    }

    /**
     * Bulk delete numbers.
     */
    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'exists:phone_numbers,id',
        ]);

        $inUseCount = PhoneNumber::whereIn('id', $request->ids)
            ->where('status', 'in_use')
            ->count();

        if ($inUseCount > 0) {
            return response()->json([
                'message' => "{$inUseCount} numbers are currently in use and cannot be deleted.",
            ], 422);
        }

        // Detach services and delete
        DB::table('number_service')->whereIn('phone_number_id', $request->ids)->delete();
        $deleted = PhoneNumber::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => "{$deleted} numbers removed from inventory.",
        ]);
    }

    /**
     * Bulk update status.
     */
    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'exists:phone_numbers,id',
            'status' => 'required|in:available,reserved,expired,retired',
        ]);

        $updated = PhoneNumber::whereIn('id', $request->ids)
            ->where('status', '!=', 'in_use')
            ->update(['status' => $request->status]);

        return response()->json([
            'message' => "{$updated} numbers updated to {$request->status}.",
        ]);
    }

    /**
     * Bulk assign services to numbers.
     */
    public function bulkAssignServices(Request $request)
    {
        $request->validate([
            'ids'          => 'required|array|min:1',
            'ids.*'        => 'exists:phone_numbers,id',
            'service_ids'  => 'required|array|min:1',
            'service_ids.*' => 'exists:services,id',
            'mode'         => 'required|in:sync,attach,detach',
        ]);

        $numbers = PhoneNumber::whereIn('id', $request->ids)->get();

        foreach ($numbers as $number) {
            if ($request->mode === 'sync') {
                $number->services()->sync($request->service_ids);
            } elseif ($request->mode === 'attach') {
                $number->services()->syncWithoutDetaching($request->service_ids);
            } elseif ($request->mode === 'detach') {
                $number->services()->detach($request->service_ids);
            }
        }

        return response()->json([
            'message' => count($numbers) . " numbers updated with services.",
        ]);
    }

    /**
     * Bulk set price.
     */
    public function bulkSetPrice(Request $request)
    {
        $request->validate([
            'ids'        => 'required|array|min:1',
            'ids.*'      => 'exists:phone_numbers,id',
            'sell_price' => 'sometimes|numeric|min:0',
            'cost_price' => 'sometimes|numeric|min:0',
        ]);

        $data = [];
        if ($request->has('sell_price')) $data['sell_price'] = $request->sell_price;
        if ($request->has('cost_price')) $data['cost_price'] = $request->cost_price;

        if (empty($data)) {
            return response()->json(['message' => 'No price values provided.'], 422);
        }

        $updated = PhoneNumber::whereIn('id', $request->ids)->update($data);

        return response()->json([
            'message' => "{$updated} numbers pricing updated.",
        ]);
    }
}
