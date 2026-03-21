<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotification extends Model
{
    protected $fillable = [
        'user_id', 'title', 'message',
        'link_url', 'link_label',
        'type', 'is_broadcast', 'is_active',
    ];

    protected $casts = [
        'is_broadcast' => 'boolean',
        'is_active'    => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: active notifications for a user (individual + broadcasts).
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('is_active', true)
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhere('is_broadcast', true);
            });
    }
}
