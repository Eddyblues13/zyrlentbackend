<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberOrder extends Model
{
    protected $fillable = [
        'user_id', 'service_id', 'country_id',
        'order_ref', 'phone_number', 'twilio_sid',
        'otp_code', 'sms_from', 'status', 'cost',
        'expires_at', 'ip_address', 'user_agent', 'completed_at',
        // Provider routing fields
        'provider_id', 'provider_slug', 'provider_response_ms',
        'retry_count', 'routing_log',
    ];

    protected $casts = [
        'cost'                => 'decimal:2',
        'expires_at'          => 'datetime',
        'completed_at'        => 'datetime',
        'routing_log'         => 'array',
        'provider_response_ms' => 'integer',
        'retry_count'         => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function provider()
    {
        return $this->belongsTo(ApiProvider::class, 'provider_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && now()->isAfter($this->expires_at);
    }
}
