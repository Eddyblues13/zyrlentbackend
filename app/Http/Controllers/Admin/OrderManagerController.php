<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NumberOrder;
use Illuminate\Http\Request;

class OrderManagerController extends Controller
{
    public function index(Request $request)
    {
        $query = NumberOrder::with('user:id,name,email', 'service:id,name,color', 'country:id,name,flag');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_ref', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $orders = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json($orders);
    }

    public function show(NumberOrder $order)
    {
        $order->load('user:id,name,email', 'service:id,name,color,cost', 'country:id,name,flag,dial_code');
        return response()->json($order);
    }
}
