<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Wallet extends Model
{
    protected $fillable = ['user_id', 'balance', 'referral_balance'];

    protected $casts = [
        'balance' => 'decimal:2',
        'referral_balance' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get total usable balance (wallet + referral credits).
     */
    public function getTotalBalanceAttribute(): float
    {
        return (float) $this->balance + (float) $this->referral_balance;
    }

    /**
     * Deduct amount from wallet and record a transaction.
     * Uses referral credits first, then main balance.
     * Throws exception if insufficient combined balance.
     */
    public function deduct(float $amount, string $description, array $meta = []): Transaction
    {
        $totalBalance = $this->total_balance;

        if ($totalBalance < $amount) {
            throw new \Exception('Insufficient wallet balance.');
        }

        $remaining = $amount;

        // Use referral credits first
        if ($this->referral_balance > 0) {
            $fromReferral = min($this->referral_balance, $remaining);
            $this->referral_balance -= $fromReferral;
            $remaining -= $fromReferral;
        }

        // Use main balance for the rest
        if ($remaining > 0) {
            $this->balance -= $remaining;
        }

        $this->save();

        return Transaction::create([
            'user_id'      => $this->user_id,
            'type'         => 'debit',
            'amount'       => $amount,
            'balance_after' => $this->total_balance,
            'description'  => $description,
            'reference'    => 'TXN-' . strtoupper(Str::random(10)),
            'status'       => 'completed',
            'meta'         => $meta,
        ]);
    }

    /**
     * Credit amount to wallet and record a transaction.
     */
    public function credit(float $amount, string $description, array $meta = []): Transaction
    {
        $this->balance += $amount;
        $this->save();

        return Transaction::create([
            'user_id'      => $this->user_id,
            'type'         => 'credit',
            'amount'       => $amount,
            'balance_after' => $this->total_balance,
            'description'  => $description,
            'reference'    => 'TXN-' . strtoupper(Str::random(10)),
            'status'       => 'completed',
            'meta'         => $meta,
        ]);
    }

    /**
     * Credit referral bonus to the referral_balance (non-withdrawable).
     */
    public function creditReferralBonus(float $amount, string $description): Transaction
    {
        $this->referral_balance += $amount;
        $this->save();

        return Transaction::create([
            'user_id'      => $this->user_id,
            'type'         => 'credit',
            'amount'       => $amount,
            'balance_after' => $this->total_balance,
            'description'  => $description,
            'reference'    => 'REF-' . strtoupper(Str::random(10)),
            'status'       => 'completed',
            'meta'         => ['type' => 'referral_bonus'],
        ]);
    }
}
