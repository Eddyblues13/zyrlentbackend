<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhoneNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone_number',
        'country_id',
        'operator',
        'provider',
        'provider_sid',
        'status',
        'cost_price',
        'sell_price',
        'assigned_order_id',
        'reserved_at',
        'expires_at',
        'times_used',
        'max_uses',
        'notes',
    ];

    protected $casts = [
        'cost_price'  => 'decimal:4',
        'sell_price'  => 'decimal:4',
        'reserved_at' => 'datetime',
        'expires_at'  => 'datetime',
        'times_used'  => 'integer',
        'max_uses'    => 'integer',
    ];

    /* ── Relationships ── */

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'number_service');
    }

    public function assignedOrder()
    {
        return $this->belongsTo(NumberOrder::class, 'assigned_order_id');
    }

    /* ── Scopes ── */

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeInUse($query)
    {
        return $query->where('status', 'in_use');
    }

    public function scopeReserved($query)
    {
        return $query->where('status', 'reserved');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeRetired($query)
    {
        return $query->where('status', 'retired');
    }

    public function scopeByCountry($query, $countryId)
    {
        return $query->where('country_id', $countryId);
    }

    public function scopeByProvider($query, $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeByOperator($query, $operator)
    {
        return $query->where('operator', $operator);
    }

    public function scopeForService($query, $serviceId)
    {
        return $query->whereHas('services', fn($q) => $q->where('services.id', $serviceId));
    }

    /* ── Helpers ── */

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function canBeReused(): bool
    {
        return $this->times_used < $this->max_uses;
    }

    public function markInUse(int $orderId): void
    {
        $this->update([
            'status'            => 'in_use',
            'assigned_order_id' => $orderId,
            'times_used'        => $this->times_used + 1,
        ]);
    }

    public function release(): void
    {
        $newStatus = $this->canBeReused() ? 'available' : 'retired';
        $this->update([
            'status'            => $newStatus,
            'assigned_order_id' => null,
            'reserved_at'       => null,
        ]);
    }
}
