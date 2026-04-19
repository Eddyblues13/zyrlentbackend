<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'referral_code',
        'referred_by',
        'is_suspended',
        'suspended_reason',
        'is_reseller',
        'last_active_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_reseller' => 'boolean',
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * Boot the model — auto-generate referral code.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->referral_code)) {
                $user->referral_code = 'ZYR-' . strtoupper(Str::random(6));
            }
        });
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function orders()
    {
        return $this->hasMany(NumberOrder::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Referrals this user has made (as the referrer).
     */
    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    /**
     * The user who referred this user.
     */
    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function supportTickets()
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function notifications()
    {
        return $this->hasMany(AdminNotification::class);
    }

    public function loginHistories()
    {
        return $this->hasMany(LoginHistory::class);
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $url = 'https://www.zyrlent.com/reset-password?token=' . $token . '&email=' . urlencode($this->email);
        $this->notify(new \App\Notifications\CustomResetPassword($url));
    }
}
