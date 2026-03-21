<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NumberOrder;
use App\Models\Referral;
use App\Models\Service;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Country;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();

        // ── Core user stats ──
        $totalUsers       = User::count();
        $activeUsersToday = User::where('last_active_at', '>=', $today)->count();
        $suspendedUsers   = User::where('is_suspended', true)->count();
        $newUsersToday    = User::where('created_at', '>=', $today)->count();
        $apiUsers         = User::whereNotNull('api_key')->count();

        // ── Activation stats ──
        $totalActivations     = NumberOrder::count();
        $activationsToday     = NumberOrder::where('created_at', '>=', $today)->count();
        $completedActivations = NumberOrder::where('status', 'completed')->count();
        $failedActivations    = NumberOrder::where('status', 'failed')->count();
        $pendingActivations   = NumberOrder::where('status', 'pending')->count();
        $waitingForSms        = NumberOrder::where('status', 'waiting')->count();
        $cancelledActivations = NumberOrder::where('status', 'cancelled')->count();
        $expiredActivations   = NumberOrder::where('status', 'expired')->count();
        $successRate          = $totalActivations > 0
            ? round(($completedActivations / $totalActivations) * 100, 1)
            : 0;

        // ── SMS received today ──
        $smsReceivedToday = NumberOrder::where('status', 'completed')
            ->where('completed_at', '>=', $today)
            ->count();

        // ── Revenue ──
        $revenueToday = Transaction::where('type', 'debit')
            ->where('created_at', '>=', $today)
            ->sum('amount');

        $revenueThisMonth = Transaction::where('type', 'debit')
            ->where('created_at', '>=', $startOfMonth)
            ->sum('amount');

        $totalRevenue = Transaction::where('type', 'debit')->sum('amount');

        // ── Available numbers ──
        $availableNumbers = Country::where('is_active', true)->sum('available_numbers');

        // ── Top countries (by orders) ──
        $topCountries = Country::withCount('orders')
            ->orderByDesc('orders_count')
            ->take(5)
            ->get(['id', 'name', 'flag', 'code'])
            ->map(fn($c) => [
                'id'     => $c->id,
                'name'   => $c->name,
                'flag'   => $c->flag,
                'code'   => $c->code,
                'orders' => $c->orders_count,
            ]);

        // ── Top services (by orders) ──
        $topServices = Service::withCount('orders')
            ->orderByDesc('orders_count')
            ->take(5)
            ->get(['id', 'name', 'icon', 'color'])
            ->map(fn($s) => [
                'id'     => $s->id,
                'name'   => $s->name,
                'icon'   => $s->icon,
                'color'  => $s->color,
                'orders' => $s->orders_count,
            ]);

        // ── Charts: last 7 days ──
        $activationChart = [];
        $revenueChart    = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $label = $date->format('M d');

            $activationChart[] = [
                'date'      => $label,
                'completed' => NumberOrder::where('status', 'completed')
                    ->whereDate('created_at', $date)->count(),
                'failed'    => NumberOrder::where('status', 'failed')
                    ->whereDate('created_at', $date)->count(),
                'total'     => NumberOrder::whereDate('created_at', $date)->count(),
            ];

            $revenueChart[] = [
                'date'    => $label,
                'revenue' => (float) Transaction::where('type', 'debit')
                    ->whereDate('created_at', $date)->sum('amount'),
            ];
        }

        // ── Recent activity ──
        $recentOrders = NumberOrder::with('user:id,name,email', 'service:id,name,color,icon', 'country:id,name,flag')
            ->latest()->take(5)->get();

        $recentUsers = User::latest()->take(5)
            ->get(['id', 'name', 'email', 'created_at', 'is_suspended']);

        // ── Other stats ──
        $activeServices = Service::where('is_active', true)->count();
        $pendingFunds   = Transaction::where('type', 'credit')
            ->where('status', 'pending')->count();
        $totalReferrals = Referral::count();
        $openTickets    = SupportTicket::whereIn('status', ['open', 'in_progress'])->count();

        return response()->json([
            // Summary cards
            'total_users'           => $totalUsers,
            'active_users_today'    => $activeUsersToday,
            'suspended_users'       => $suspendedUsers,
            'new_users_today'       => $newUsersToday,
            'api_users'             => $apiUsers,

            'total_activations'     => $totalActivations,
            'activations_today'     => $activationsToday,
            'completed_activations' => $completedActivations,
            'failed_activations'    => $failedActivations,
            'pending_activations'   => $pendingActivations,
            'waiting_for_sms'       => $waitingForSms,
            'cancelled_activations' => $cancelledActivations,
            'expired_activations'   => $expiredActivations,
            'success_rate'          => $successRate,
            'sms_received_today'    => $smsReceivedToday,

            'available_numbers'     => $availableNumbers,

            'revenue_today'         => (float) $revenueToday,
            'revenue_this_month'    => (float) $revenueThisMonth,
            'total_revenue'         => (float) $totalRevenue,

            'active_services'       => $activeServices,
            'pending_funds'         => $pendingFunds,
            'total_referrals'       => $totalReferrals,
            'open_tickets'          => $openTickets,

            // Rankings
            'top_countries'         => $topCountries,
            'top_services'          => $topServices,

            // Charts
            'activation_chart'      => $activationChart,
            'revenue_chart'         => $revenueChart,

            // Recent
            'recent_orders'         => $recentOrders,
            'recent_users'          => $recentUsers,
        ]);
    }
}
