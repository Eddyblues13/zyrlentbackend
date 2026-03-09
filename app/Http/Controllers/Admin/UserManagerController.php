<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class UserManagerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('suspended')) {
            $query->where('is_suspended', $request->boolean('suspended'));
        }

        $users = $query->withCount('orders')
            ->with('wallet:id,user_id,balance')
            ->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json($users);
    }

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

        return response()->json($user);
    }

    public function creditWallet(Request $request, User $user)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $wallet = $user->wallet ?? Wallet::create(['user_id' => $user->id, 'balance' => 0]);
        $wallet->credit($request->amount, 'Admin credit: ' . ($request->note ?? 'Manual top-up'));

        return response()->json([
            'message' => "₦" . number_format($request->amount, 2) . " credited to {$user->name}'s wallet.",
            'balance' => $wallet->fresh()->balance,
        ]);
    }

    /**
     * Deduct from user's wallet.
     */
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

    /**
     * Suspend or unsuspend a user.
     */
    public function toggleSuspend(Request $request, User $user)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $user->is_suspended = !$user->is_suspended;
        $user->suspended_reason = $user->is_suspended ? ($request->reason ?? 'Suspended by admin') : null;
        $user->save();

        // Revoke all tokens if suspended
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

    /**
     * Send email to a user.
     */
    public function sendEmail(Request $request, User $user)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'body'    => 'required|string|max:5000',
        ]);

        try {
            Mail::raw($request->body, function ($mail) use ($user, $request) {
                $mail->to($user->email)
                     ->subject($request->subject);
            });

            return response()->json(['message' => "Email sent to {$user->email}."]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to send email: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Send notification to a user.
     */
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

    /**
     * Login as user — generates a temporary token for the admin.
     */
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
