<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotification extends Model
{
    protected $fillable = [
        'user_id', 'title', 'message', 'type',
        'is_broadcast', 'is_read',
    ];

    protected $casts = [
        'is_broadcast' => 'boolean',
        'is_read'      => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: notifications for a specific user (individual + broadcasts).
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('user_id', $userId)
              ->orWhere('is_broadcast', true);
        });
    }
}
