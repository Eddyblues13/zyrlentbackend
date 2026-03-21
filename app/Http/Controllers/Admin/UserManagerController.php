<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\LoginHistory;
use App\Models\NumberOrder;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserManagerController extends Controller
{
    /**
     * List users with search + filter tabs.
     */
    public function index(Request $request)
    {
        $query = User::query();

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('referral_code', 'like', "%{$search}%");
            });
        }

        // Filter tabs
        if ($filter = $request->input('filter')) {
            switch ($filter) {
                case 'active':
                    $query->where('is_suspended', false);
                    break;
                case 'suspended':
                    $query->where('is_suspended', true);
                    break;
                case 'new':
                    $query->where('created_at', '>=', now()->subDays(7));
                    break;
                case 'top':
                    $query->withCount('orders')->orderBy('orders_count', 'desc');
                    break;
                case 'api':
                    $query->whereNotNull('api_key');
                    break;
                case 'reseller':
                    $query->where('is_reseller', true);
                    break;
            }
        }

        // Legacy filter
        if ($request->has('suspended')) {
            $query->where('is_suspended', $request->boolean('suspended'));
        }

        $users = $query->withCount('orders')
            ->with('wallet:id,user_id,balance')
            ->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json($users);
    }

    /**
     * User stats for the management page.
     */
    public function stats()
    {
        return response()->json([
            'total'      => User::count(),
            'active'     => User::where('is_suspended', false)->count(),
            'suspended'  => User::where('is_suspended', true)->count(),
            'new_today'  => User::whereDate('created_at', today())->count(),
            'new_week'   => User::where('created_at', '>=', now()->subDays(7))->count(),
            'api_users'  => User::whereNotNull('api_key')->count(),
            'resellers'  => User::where('is_reseller', true)->count(),
        ]);
    }

    /**
     * Show a single user with related data.
     */
    public function show(User $user)
    {
        $user->load([
            'wallet',
            'orders' => fn($q) => $q->with('service:id,name,color,icon', 'country:id,name,flag')->latest()->take(10),
            'transactions' => fn($q) => $q->latest()->take(10),
            'referrals.referred:id,name,email,created_at',
            'referredBy:id,name,email',
        ]);

        $user->loadCount(['orders', 'referrals', 'supportTickets']);

        // Append extra computed fields
        $user->setAttribute('is_reseller', (bool) $user->is_reseller);
        $user->setAttribute('has_api_key', !is_null($user->api_key));
        $user->setAttribute('last_active_at', $user->last_active_at);

        return response()->json($user);
    }

    /**
     * Login history for a specific user.
     */
    public function loginHistory(User $user, Request $request)
    {
        $history = LoginHistory::where('user_id', $user->id)
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($history);
    }

    /**
     * IP logs for a specific user — aggregated from login_histories + number_orders.
     */
    public function ipLogs(User $user)
    {
        // Get distinct IPs from login history with meta
        $loginIps = LoginHistory::where('user_id', $user->id)
            ->selectRaw('ip_address, MAX(created_at) as last_seen, COUNT(*) as login_count, MAX(location) as location, MAX(device) as device, MAX(browser) as browser, MAX(platform) as platform')
            ->groupBy('ip_address')
            ->get()
            ->keyBy('ip_address');

        // Merge with any IPs from orders (if ip_address column exists)
        $ips = $loginIps->map(function ($row) {
            return [
                'ip_address'  => $row->ip_address,
                'last_seen'   => $row->last_seen,
                'login_count' => $row->login_count,
                'location'    => $row->location,
                'device'      => $row->device,
                'browser'     => $row->browser,
                'platform'    => $row->platform,
            ];
        })->values();

        return response()->json([
            'total_ips' => $ips->count(),
            'ips'       => $ips,
        ]);
    }

    /**
     * Activation (order) history for a specific user — paginated.
     */
    public function activationHistory(User $user, Request $request)
    {
        $orders = NumberOrder::where('user_id', $user->id)
            ->with('service:id,name,color,icon', 'country:id,name,flag')
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($orders);
    }

    /**
     * Toggle reseller status.
     */
    public function toggleReseller(User $user)
    {
        $user->is_reseller = !$user->is_reseller;
        $user->save();

        return response()->json([
            'message'     => $user->is_reseller
                ? "{$user->name} is now a reseller."
                : "{$user->name} is no longer a reseller.",
            'is_reseller' => $user->is_reseller,
        ]);
    }

    // ─── Existing actions (unchanged) ─────────────────────

    public function creditWallet(Request $request, User $user)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'note'   => 'nullable|string|max:255',
        ]);

        $wallet = $user->wallet ?? Wallet::create(['user_id' => $user->id, 'balance' => 0]);
        $wallet->credit($request->amount, 'Admin credit: ' . ($request->note ?? 'Manual top-up'));

        return response()->json([
            'message' => "₦" . number_format($request->amount, 2) . " credited to {$user->name}'s wallet.",
            'balance' => $wallet->fresh()->balance,
        ]);
    }

    public function debitWallet(Request $request, User $user)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'note'   => 'nullable|string|max:255',
        ]);

        $wallet = $user->wallet;
        if (!$wallet || $wallet->total_balance < $request->amount) {
            return response()->json(['message' => 'Insufficient balance.'], 422);
        }

        $wallet->deduct($request->amount, 'Admin debit: ' . ($request->note ?? 'Manual deduction'));

        return response()->json([
            'message' => "₦" . number_format($request->amount, 2) . " deducted from {$user->name}'s wallet.",
            'balance' => $wallet->fresh()->balance,
        ]);
    }

    public function toggleSuspend(Request $request, User $user)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $user->is_suspended = !$user->is_suspended;
        $user->suspended_reason = $user->is_suspended ? ($request->reason ?? 'Suspended by admin') : null;
        $user->save();

        if ($user->is_suspended) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => $user->is_suspended
                ? "{$user->name} has been suspended."
                : "{$user->name} has been unsuspended.",
            'is_suspended' => $user->is_suspended,
        ]);
    }

    public function resetPassword(User $user)
    {
        $newPassword = Str::random(12);
        $user->update(['password' => Hash::make($newPassword)]);
        $user->tokens()->delete();

        return response()->json([
            'message'      => "Password reset for {$user->name}.",
            'new_password' => $newPassword,
        ]);
    }

    public function sendEmail(Request $request, User $user)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'body'    => 'required|string|max:5000',
        ]);

        try {
            Mail::raw($request->body, function ($mail) use ($user, $request) {
                $mail->to($user->email)->subject($request->subject);
            });

            return response()->json(['message' => "Email sent to {$user->email}."]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to send email: ' . $e->getMessage()], 500);
        }
    }

    public function sendNotification(Request $request, User $user)
    {
        $request->validate([
            'title'   => 'required|string|max:255',
            'message' => 'required|string|max:2000',
            'type'    => 'nullable|in:info,warning,promo,system',
        ]);

        AdminNotification::create([
            'user_id'      => $user->id,
            'title'        => $request->title,
            'message'      => $request->message,
            'type'         => $request->type ?? 'info',
            'is_broadcast' => false,
        ]);

        return response()->json(['message' => "Notification sent to {$user->name}."]);
    }

    public function loginAsUser(User $user)
    {
        if ($user->is_suspended) {
            return response()->json(['message' => 'Cannot login as suspended user.'], 422);
        }

        $token = $user->createToken('admin-login-as', ['*'], now()->addHour())->plainTextToken;

        return response()->json([
            'message' => "Logged in as {$user->name}. Token expires in 1 hour.",
            'token'   => $token,
            'user'    => $user->only(['id', 'name', 'email']),
        ]);
    }
}
