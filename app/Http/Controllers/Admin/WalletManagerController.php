<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\Request;

class WalletManagerController extends Controller
{
    /**
     * List pending manual fund requests.
     */
    public function pendingFunds(Request $request)
    {
        $query = Transaction::where('type', 'credit')
            ->where('status', 'pending')
            ->with('user:id,name,email');

        $funds = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json($funds);
    }

    /**
     * Approve a pending fund request — credit the user's wallet.
     */
    public function confirmFund(Transaction $transaction)
    {
        if ($transaction->status !== 'pending') {
            return response()->json(['message' => 'This transaction is already processed.'], 422);
        }

        $wallet = Wallet::where('user_id', $transaction->user_id)->first();

        if (! $wallet) {
            $wallet = Wallet::create(['user_id' => $transaction->user_id, 'balance' => 0]);
        }

        // Credit the wallet
        $wallet->increment('balance', $transaction->amount);
        $transaction->update([
            'status' => 'completed',
            'balance_after' => $wallet->fresh()->balance,
        ]);

        return response()->json([
            'message' => '₦' . number_format($transaction->amount, 2) . ' credited successfully.',
            'transaction' => $transaction->fresh()->load('user:id,name,email'),
        ]);
    }

    /**
     * Reject a pending fund request.
     */
    public function rejectFund(Request $request, Transaction $transaction)
    {
        if ($transaction->status !== 'pending') {
            return response()->json(['message' => 'This transaction is already processed.'], 422);
        }

        $request->validate(['reason' => 'nullable|string|max:255']);

        $transaction->update([
            'status' => 'failed',
            'meta' => array_merge($transaction->meta ?? [], [
                'reject_reason' => $request->input('reason', 'Rejected by admin'),
            ]),
        ]);

        return response()->json([
            'message' => 'Fund request rejected.',
            'transaction' => $transaction->fresh()->load('user:id,name,email'),
        ]);
    }
}
