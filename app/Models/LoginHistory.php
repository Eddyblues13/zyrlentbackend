<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginHistory extends Model
{
    protected $fillable = [
        'user_id', 'ip_address', 'user_agent',
        'device', 'browser', 'platform', 'location', 'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
