<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Service;
use App\Models\Country;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Ensure wallet exists, create if not
        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id], ['balance' => 0.00]);

        $orders = $user->orders();

        return response()->json([
            'wallet_balance' => $wallet->balance,
            'stats' => [
                'transactions' => $orders->count(), // simplified assumption for transactions
                'verifications' => $orders->where('status', 'completed')->count(),
                'total_spent' => $orders->sum('cost'),
                'pending_sms' => $orders->where('status', 'pending')->count(),
            ]
        ]);
    }

    public function services()
    {
        return response()->json(Service::select('id', 'name', 'icon')->get());
    }

    public function countries()
    {
        return response()->json(Country::select('id', 'name', 'code', 'flag', 'success_rate')->get());
    }
}
