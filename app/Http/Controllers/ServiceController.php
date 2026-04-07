<?php

namespace App\Http\Controllers;

use App\Models\Service;

class ServiceController extends Controller
{
    public function index()
    {
        $services = Service::where('is_active', true)
            ->withCount('orders')
            ->orderByDesc('orders_count')
            ->orderBy('name')
            ->get()
            ->map(function ($service, $index) {
                return [
                    'id'          => $service->id,
                    'name'        => $service->name,
                    'icon'        => $service->icon ?? null,
                    'color'       => $service->color ?? '#6366f1',
                    'category'    => $service->category ?? 'Verification',
                    'cost'        => (float) ($service->cost ?? 0),
                    'order_count' => $service->orders_count ?? 0,
                    'is_popular'  => $index < 5,
                ];
            });

        return response()->json($services);
    }
}
