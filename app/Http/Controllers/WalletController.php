<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * Return wallet balance.
     */
    public function balance(Request $request)
    {
        $wallet = $request->user()->wallet()->firstOrCreate(
            ['user_id' => $request->user()->id],
            ['balance' => 0.00]
        );

        return response()->json([
            'balance' => $wallet->balance,
        ]);
    }

    /**
     * Return paginated transaction history.
     */
    public function transactions(Request $request)
    {
        $query = $request->user()->transactions()->latest();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        return response()->json($query->paginate($request->get('per_page', 10)));
    }

    /**
     * Manual OPay fund request — saves a pending credit and notifies admin.
     */
    public function manualFund(Request $request)
    {
        $validated = $request->validate([
            'amount'    => 'required|numeric|min:100',
            'reference' => 'required|string|min:5',
        ]);

        $user = $request->user();

        // Check no duplicate reference
        $exists = Transaction::where('reference', $validated['reference'])->exists();
        if ($exists) {
            return response()->json([
                'message' => 'This transfer reference has already been submitted.',
            ], 422);
        }

        // Create a PENDING credit (admin must confirm)
        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id], ['balance' => 0.00]);

        Transaction::create([
            'user_id'      => $user->id,
            'type'         => 'credit',
            'amount'       => $validated['amount'],
            'balance_after' => $wallet->balance, // unchanged until admin approves
            'description'  => 'Manual OPay top-up (pending confirmation)',
            'reference'    => $validated['reference'],
            'status'       => 'pending',
            'meta'         => ['channel' => 'opay_manual', 'amount' => $validated['amount']],
        ]);

        return response()->json([
            'message' => 'Transfer submitted successfully. Your wallet will be credited within 5–30 minutes after confirmation.',
        ]);
    }
}
