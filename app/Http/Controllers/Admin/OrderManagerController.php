<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NumberOrder;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderManagerController extends Controller
{
    public function index(Request $request)
    {
        $query = NumberOrder::with(
            'user:id,name,email',
            'service:id,name,icon,color',
            'country:id,name,flag'
        );

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_ref', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhere('otp_code', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $orders = $query->latest()->paginate($request->input('per_page', 15));
        return response()->json($orders);
    }

    public function show(NumberOrder $order)
    {
        $order->load(
            'user:id,name,email',
            'service:id,name,icon,color,cost',
            'country:id,name,flag,dial_code'
        );
        return response()->json($order);
    }

    /**
     * Cancel an activation & refund user
     */
    public function cancel(NumberOrder $order)
    {
        if (in_array($order->status, ['completed', 'cancelled', 'expired'])) {
            return response()->json(['message' => 'Cannot cancel — order is already ' . $order->status], 422);
        }

        $order->update(['status' => 'cancelled']);

        // Refund user
        $this->refundOrder($order, 'Admin cancelled activation');

        return response()->json(['message' => 'Order cancelled and refunded']);
    }

    /**
     * Force-complete an activation
     */
    public function forceComplete(Request $request, NumberOrder $order)
    {
        if ($order->status === 'completed') {
            return response()->json(['message' => 'Order is already completed'], 422);
        }

        $order->update([
            'status'       => 'completed',
            'otp_code'     => $request->input('otp_code', $order->otp_code),
            'completed_at' => now(),
        ]);

        return response()->json(['message' => 'Order force-completed']);
    }

    /**
     * Refund a completed/failed order
     */
    public function refund(NumberOrder $order)
    {
        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'Order was already cancelled & refunded'], 422);
        }

        $this->refundOrder($order, 'Admin refund');

        return response()->json(['message' => 'User refunded ₦' . number_format($order->cost, 2)]);
    }

    /**
     * Resend / simulate SMS (for testing or retrigger)
     */
    public function resendSms(NumberOrder $order)
    {
        if (!$order->phone_number) {
            return response()->json(['message' => 'No phone number assigned to this order'], 422);
        }

        // Mark order as waiting again
        $order->update([
            'status'     => 'waiting',
            'expires_at' => now()->addMinutes(10),
        ]);

        return response()->json(['message' => 'Order reset to waiting for SMS. Expires in 10 min.']);
    }

    /**
     * Get activation stats summary for the orders page
     */
    public function stats()
    {
        return response()->json([
            'total'     => NumberOrder::count(),
            'pending'   => NumberOrder::where('status', 'pending')->count(),
            'waiting'   => NumberOrder::where('status', 'waiting')->count(),
            'completed' => NumberOrder::where('status', 'completed')->count(),
            'cancelled' => NumberOrder::where('status', 'cancelled')->count(),
            'expired'   => NumberOrder::where('status', 'expired')->count(),
            'failed'    => NumberOrder::where('status', 'failed')->count(),
        ]);
    }

    /* ── internal helper ── */
    private function refundOrder(NumberOrder $order, string $reason)
    {
        $user   = $order->user;
        $amount = $order->cost ?? 0;

        if ($amount <= 0 || !$user) return;

        $wallet = Wallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        $wallet->increment('balance', $amount);

        Transaction::create([
            'user_id'       => $user->id,
            'type'          => 'refund',
            'amount'        => $amount,
            'balance_after' => $wallet->balance,
            'description'   => $reason . ' — Order #' . $order->order_ref,
            'reference'     => 'REF-' . strtoupper(Str::random(10)),
            'status'        => 'completed',
        ]);
    }
}
