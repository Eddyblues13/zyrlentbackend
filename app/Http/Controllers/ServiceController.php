<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Support\Facades\Schema;

class ServiceController extends Controller
{
    public function index()
    {
        // Check which optional columns exist in the services table
        $hasColor    = Schema::hasColumn('services', 'color');
        $hasCategory = Schema::hasColumn('services', 'category');
        $hasIcon     = Schema::hasColumn('services', 'icon');
        $hasSortOrder = Schema::hasColumn('services', 'sort_order');
        $hasCost     = Schema::hasColumn('services', 'cost');

        // Build the safe column list — only select columns that exist
        $selectCols = ['id', 'name', 'is_active'];
        if ($hasIcon)     $selectCols[] = 'icon';
        if ($hasColor)    $selectCols[] = 'color';
        if ($hasCategory) $selectCols[] = 'category';
        if ($hasCost)     $selectCols[] = 'cost';
        if ($hasSortOrder) $selectCols[] = 'sort_order';

        // Check if the orders relationship can be counted safely
        $hasOrdersTable = Schema::hasTable('number_orders');

        $query = Service::where('is_active', true)->select($selectCols);

        if ($hasOrdersTable) {
            $query->withCount('orders');
        }

        if ($hasSortOrder) {
            $query->orderByDesc('sort_order');
        }
        if ($hasOrdersTable) {
            $query->orderByDesc('orders_count');
        }
        $query->orderBy('name');

        $services = $query->get()->map(function ($service, $index) use ($hasOrdersTable, $hasCost) {
            return [
                'id'          => $service->id,
                'name'        => $service->name,
                'icon'        => $service->icon ?? null,
                'color'       => $service->color ?? '#6366f1',
                'category'    => $service->category ?? 'Verification',
                'cost'        => $hasCost ? (float) ($service->cost ?? 0) : 0.0,
                'order_count' => $hasOrdersTable ? ($service->orders_count ?? 0) : 0,
                'is_popular'  => $index < 5,
            ];
        });

        return response()->json($services);
    }
}
