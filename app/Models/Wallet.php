<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Wallet extends Model
{
    protected $fillable = ['user_id', 'balance'];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Deduct amount from wallet and record a transaction.
     * Throws exception if insufficient balance.
     */
    public function deduct(float $amount, string $description, array $meta = []): Transaction
    {
        if ($this->balance < $amount) {
            throw new \Exception('Insufficient wallet balance.');
        }

        $this->balance -= $amount;
        $this->save();

        return Transaction::create([
            'user_id'      => $this->user_id,
            'type'         => 'debit',
            'amount'       => $amount,
            'balance_after' => $this->balance,
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
            'balance_after' => $this->balance,
            'description'  => $description,
            'reference'    => 'TXN-' . strtoupper(Str::random(10)),
            'status'       => 'completed',
            'meta'         => $meta,
        ]);
    }
}
