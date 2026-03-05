<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $fillable = [
        'name', 'code', 'flag', 'success_rate',
        'dial_code', 'twilio_code', 'price_usd', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_usd' => 'decimal:4',
    ];

    public function orders()
    {
        return $this->hasMany(NumberOrder::class);
    }
}
