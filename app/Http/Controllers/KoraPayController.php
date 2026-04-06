<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KoraPayController extends Controller
{
    /**
     * Initialize a KoraPay transaction for wallet funding.
     */
    public function initialize(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100', // Minimum 100 NGN
        ]);

        $user = $request->user();
        $amount = (float) $request->amount;
        $reference = 'fund_'.Str::random(12).'_'.time();

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

        // Build redirect URL — use the frontend URL with the verify-korapay path
        $redirectUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/')
            .'/dashboard/wallet/verify-korapay';

        $response = Http::withToken($secretKey)
            ->post('https://api.korapay.com/merchant/api/v1/charges/initialize', [
                'reference' => $reference,
                'customer' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'amount' => $amount,
                'currency' => 'NGN',
                'redirect_url' => $redirectUrl,
            ]);

        if ($response->successful() && $response->json('status') === true) {
            $checkoutUrl = $response->json('data.checkout_url');

            return response()->json([
                'success' => true,
                'checkout_url' => $checkoutUrl,
                'reference' => $reference,
                'email' => $user->email,
                'customer_name' => $user->name,
            ]);
        }

        // If Korapay API fails
        Log::error('KoraPay initialization failed', [
            'user_id' => $user->id,
            'amount' => $amount,
            'response' => $response->json(),
        ]);

        $transaction->update([
            'status' => 'failed',
            'description' => 'Korapay initialization failed.',
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to initialize payment gateway. Please try again.',
        ], 400);
    }

    /**
     * Verify a KoraPay transaction after frontend redirect.
     *
     * This endpoint is called by the frontend after KoraPay redirects the user
     * back. It checks the transaction status with KoraPay's API and credits the
     * user's wallet if the payment was successful.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'reference' => 'required|string',
        ]);

        $reference = $request->reference;
        $transaction = Transaction::where('reference', $reference)->first();

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found.',
            ], 404);
        }

        // Already completed — return success immediately
        if ($transaction->status === 'completed') {
            $wallet = $transaction->user?->wallet;

            return response()->json([
                'success' => true,
                'message' => 'Transaction already completed.',
                'balance' => $wallet?->balance,
            ]);
        }

        // Already marked failed — allow re-verification (KoraPay may have processed it late)
        $secretKey = config('services.korapay.secret_key');

        try {
            $response = Http::withToken($secretKey)
                ->timeout(15)
                ->get("https://api.korapay.com/merchant/api/v1/charges/{$reference}");
        } catch (\Exception $e) {
            Log::error('KoraPay verification HTTP error', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not reach payment provider. Please try again in a moment.',
            ], 502);
        }

        if (! $response->successful()) {
            Log::warning('KoraPay verification response not successful', [
                'reference' => $reference,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed. Please try again.',
            ], 400);
        }

        $data = $response->json('data', []);
        $paymentStatus = $data['status'] ?? null;
        $apiStatus = $response->json('status');

        Log::info('KoraPay verify response', [
            'reference' => $reference,
            'api_status' => $apiStatus,
            'payment_status' => $paymentStatus,
        ]);

        // ── Success ──
        if ($apiStatus === true && $paymentStatus === 'success') {
            return $this->creditWallet($transaction);
        }

        // ── Still processing ──
        if (in_array($paymentStatus, ['processing', 'pending', null])) {
            return response()->json([
                'success' => false,
                'message' => 'Payment is still being processed. Please wait a moment and try again.',
            ], 400);
        }

        // ── Failed / expired ──
        if (in_array($paymentStatus, ['failed', 'expired'])) {
            $transaction->update(['status' => 'failed']);

            return response()->json([
                'success' => false,
                'message' => 'Payment was not completed. Please try again.',
            ], 400);
        }

        // ── Unknown status ──
        return response()->json([
            'success' => false,
            'message' => 'Unable to confirm payment status. Please contact support if you were charged.',
        ], 400);
    }

    /**
     * Credit the user's wallet after a successful KoraPay payment.
     */
    private function creditWallet(Transaction $transaction): \Illuminate\Http\JsonResponse
    {
        // Prevent double-credit with a re-check
        $transaction->refresh();
        if ($transaction->status === 'completed') {
            return response()->json([
                'success' => true,
                'message' => 'Transaction already completed.',
                'balance' => $transaction->user?->wallet?->balance,
            ]);
        }

        $user = $transaction->user;
        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);

        $wallet->increment('balance', $transaction->amount);

        $transaction->update([
            'status' => 'completed',
            'balance_after' => $wallet->fresh()->balance,
            'description' => 'Wallet successfully funded via Korapay',
        ]);

        // ── Referral qualification check ──
        if ($transaction->amount >= 10000 && $user->referred_by) {
            $referral = \App\Models\Referral::where('referred_id', $user->id)
                ->where('status', 'pending')
                ->first();

            if ($referral) {
                $referral->update([
                    'status' => 'credited',
                    'credited_at' => now(),
                ]);

                $referrerWallet = $referral->referrer->wallet()
                    ->firstOrCreate(['user_id' => $referral->referrer_id]);
                $referrerWallet->creditReferralBonus(
                    2000,
                    'Referral bonus: '.$user->name.' funded their wallet'
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Wallet funded successfully!',
            'balance' => $wallet->fresh()->balance,
        ]);
    }
}
