<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Transaction;
use Illuminate\Support\Str;

class KoraPayController extends Controller
{
    /**
     * Initialize a KoraPay transaction for wallet funding
     */
    public function initialize(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100', // Minimum 100 NGN
        ]);

        $user = $request->user();
        $amount = (float) $request->amount;
        $reference = 'fund_' . Str::random(12) . '_' . time();

        // 1. Create a pending transaction in the database
        $transaction = Transaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => $amount,
            'description' => 'Wallet Funding via Korapay',
            'reference' => $reference,
            'status' => 'pending',
        ]);

        // 2. Call Korapay API to initialize checkout
        $secretKey = config('services.korapay.secret_key');
        
        $response = Http::withToken($secretKey)
            ->post('https://api.korapay.com/merchant/api/v1/charges/initialize', [
                'reference' => $reference,
                'customer' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'amount' => $amount,
                'currency' => 'NGN',
                'redirect_url' => config('app.frontend_url') . '/dashboard/wallet/verify-korapay',
            ]);

        if ($response->successful() && $response->json('status') === true) {
            $checkoutUrl = $response->json('data.checkout_url');
            return response()->json([
                'success' => true,
                'checkout_url' => $checkoutUrl,
                'reference' => $reference
            ]);
        }

        // If Korapay API fails
        $transaction->update(['status' => 'failed', 'description' => 'Korapay initialization failed.']);

        return response()->json([
            'success' => false,
            'message' => 'Failed to initialize payment gateway. Please try again.',
            'error' => $response->json()
        ], 400);
    }

    /**
     * Verify a KoraPay transaction after frontend redirect
     */
    public function verify(Request $request)
    {
        $request->validate([
            'reference' => 'required|string',
        ]);

        $reference = $request->reference;
        $transaction = Transaction::where('reference', $reference)->first();

        if (!$transaction) {
            return response()->json(['success' => false, 'message' => 'Transaction not found'], 404);
        }

        if ($transaction->status === 'completed') {
            return response()->json(['success' => true, 'message' => 'Transaction already completed']);
        }

        $secretKey = config('services.korapay.secret_key');
        
        $response = Http::withToken($secretKey)
            ->get("https://api.korapay.com/merchant/api/v1/charges/{$reference}");

        if ($response->successful() && $response->json('status') === true) {
            $paymentStatus = $response->json('data.status');

            if ($paymentStatus === 'success') {
                // Credit the user's wallet
                $user = $transaction->user;
                $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
                
                $wallet->increment('balance', $transaction->amount);
                
                $transaction->update([
                    'status' => 'completed',
                    'description' => 'Wallet successfully funded via Korapay'
                ]);

                return response()->json([
                    'success' => true, 
                    'message' => 'Wallet funded successfully',
                    'balance' => $wallet->balance
                ]);
            } else if (in_array($paymentStatus, ['failed', 'expired'])) {
                $transaction->update(['status' => 'failed']);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Payment validation failed or is still pending.'
        ], 400);
    }
}
