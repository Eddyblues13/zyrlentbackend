<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NumberOrder;
use App\Models\Referral;
use App\Models\Service;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;

class AdminDashboardController extends Controller
{
    public function index()
    {
        return response()->json([
            'total_users'      => User::count(),
            'suspended_users'  => User::where('is_suspended', true)->count(),
            'total_orders'     => NumberOrder::count(),
            'active_services'  => Service::where('is_active', true)->count(),
            'total_revenue'    => Transaction::where('type', 'debit')->sum('amount'),
            'pending_funds'    => Transaction::where('type', 'credit')
                ->where('status', 'pending')->count(),
            'total_referrals'  => Referral::count(),
            'open_tickets'     => SupportTicket::whereIn('status', ['open', 'in_progress'])->count(),
            'recent_orders'    => NumberOrder::with('user:id,name,email', 'service:id,name,color')
                ->latest()->take(5)->get(),
            'recent_users'     => User::latest()->take(5)
                ->get(['id', 'name', 'email', 'created_at', 'is_suspended']),
        ]);
    }
}
