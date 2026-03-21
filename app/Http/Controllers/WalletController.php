<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
     * Manual bank-transfer fund request — saves a pending credit and notifies support via email.
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
            'user_id'       => $user->id,
            'type'          => 'credit',
            'amount'        => $validated['amount'],
            'balance_after' => $wallet->balance, // unchanged until admin approves
            'description'   => 'Manual bank transfer top-up (pending confirmation)',
            'reference'     => $validated['reference'],
            'status'        => 'pending',
            'meta'          => ['channel' => 'bank_transfer_manual', 'amount' => $validated['amount']],
        ]);

        // Send email notification to support
        try {
            $supportEmail = config('mail.from.address', 'support@zyrlent.com');
            $subject = 'New Manual Fund Request — ₦' . number_format($validated['amount'], 2);
            $body = "A new manual bank-transfer fund request has been submitted.\n\n"
                . "User: {$user->name} ({$user->email})\n"
                . "Amount: ₦" . number_format($validated['amount'], 2) . "\n"
                . "Reference: {$validated['reference']}\n"
                . "Date: " . now()->format('d M Y, h:i A') . "\n\n"
                . "Please verify the payment and approve it from the admin dashboard.";

            Mail::raw($body, function ($mail) use ($supportEmail, $subject) {
                $mail->to($supportEmail)->subject($subject);
            });
        } catch (\Exception $e) {
            // Log but don't fail the request if email fails
            \Log::warning('Manual fund email notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Transfer submitted successfully. Your wallet will be credited within 5–30 minutes after confirmation.',
        ]);
    }
}
