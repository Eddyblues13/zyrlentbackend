<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ApiProvider extends Model
{
    protected $fillable = [
        'name', 'slug', 'type', 'credentials', 'settings',
        'description', 'is_active', 'capabilities',
        // Routing fields
        'priority', 'success_rate', 'avg_response_ms',
        'total_requests', 'total_successes', 'total_failures',
        'cost_multiplier', 'markup_percent', 'routing_mode',
    ];

    protected $casts = [
        'settings'        => 'array',
        'capabilities'    => 'array',
        'is_active'       => 'boolean',
        'priority'        => 'integer',
        'success_rate'    => 'decimal:2',
        'avg_response_ms' => 'integer',
        'total_requests'  => 'integer',
        'total_successes' => 'integer',
        'total_failures'  => 'integer',
        'cost_multiplier' => 'decimal:2',
        'markup_percent' => 'decimal:2',
    ];

    /* ── Encrypt credentials on set ── */
    public function setCredentialsAttribute($value)
    {
        $this->attributes['credentials'] = $value
            ? Crypt::encryptString(is_string($value) ? $value : json_encode($value))
            : null;
    }

    /* ── Decrypt credentials on get ── */
    public function getCredentialsAttribute($value)
    {
        if (!$value) return [];
        try {
            $decrypted = Crypt::decryptString($value);
            return json_decode($decrypted, true) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /* ── Helper: check if provider has required credentials ── */
    public function isConfigured(): bool
    {
        $creds = $this->credentials;
        if (empty($creds)) return false;

        return match ($this->type) {
            'twilio'  => !empty($creds['account_sid']) && !empty($creds['auth_token']),
            '5sim'    => !empty($creds['api_key']),
            'smspool' => !empty($creds['api_key']),
            'smspva'  => !empty($creds['api_key']),
            'telnyx'  => !empty($creds['api_key']),
            'plivo'   => !empty($creds['auth_id']) && !empty($creds['auth_token']),
            'vonage'  => !empty($creds['api_key']) && !empty($creds['api_secret']),
            default   => !empty($creds),
        };
    }

    /* ── Helper: get a specific credential ── */
    public function getCredential(string $key, $default = null)
    {
        return $this->credentials[$key] ?? $default;
    }

    /* ── Helper: get a specific setting ── */
    public function getSetting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    /* ── Required credential fields per type ── */
    public static function credentialFields(string $type): array
    {
        return match ($type) {
            'twilio' => [
                ['key' => 'account_sid',    'label' => 'Account SID',    'placeholder' => 'ACxxxxxxxx'],
                ['key' => 'auth_token',     'label' => 'Auth Token',     'placeholder' => 'Your auth token'],
            ],
            'telnyx' => [
                ['key' => 'api_key', 'label' => 'API Key', 'placeholder' => 'Your Telnyx API key'],
            ],
            'plivo' => [
                ['key' => 'auth_id',    'label' => 'Auth ID',    'placeholder' => 'Your Plivo Auth ID'],
                ['key' => 'auth_token', 'label' => 'Auth Token', 'placeholder' => 'Your Plivo Auth Token'],
            ],
            'vonage' => [
                ['key' => 'api_key',    'label' => 'API Key',    'placeholder' => 'Your Vonage API key'],
                ['key' => 'api_secret', 'label' => 'API Secret', 'placeholder' => 'Your Vonage API secret'],
            ],
            '5sim' => [
                ['key' => 'api_key', 'label' => 'API Key', 'placeholder' => 'Your 5SIM API key'],
            ],
            'smspva' => [
                ['key' => 'api_key', 'label' => 'API Key', 'placeholder' => 'Your SMSPVA API key'],
            ],
            'sms_activate' => [
                ['key' => 'api_key', 'label' => 'API Key', 'placeholder' => 'Your SMS-Activate API key'],
            ],
            'smspool' => [
                ['key' => 'api_key', 'label' => 'API Key', 'placeholder' => 'Your SMSPool API key (32 characters)'],
            ],
            default => [],
        };
    }

    /* ── Optional setting fields per type ── */
    public static function settingFields(string $type): array
    {
        return match ($type) {
            'twilio' => [
                ['key' => 'phone_number',    'label' => 'Default Phone Number', 'placeholder' => '+1234567890'],
                ['key' => 'webhook_url',     'label' => 'SMS Webhook URL',      'placeholder' => 'https://yourdomain.com/api/webhook/sms'],
                ['key' => 'webhook_secret',  'label' => 'Webhook Secret',       'placeholder' => 'whsec_xxxxx'],
            ],
            'telnyx' => [
                ['key' => 'messaging_profile_id', 'label' => 'Messaging Profile ID', 'placeholder' => '4001xxxxxxx'],
                ['key' => 'webhook_url',          'label' => 'Webhook URL',           'placeholder' => 'https://yourdomain.com/api/webhook/telnyx'],
                ['key' => 'public_key',           'label' => 'Public Key (Ed25519)',  'placeholder' => 'Base64 public key from Telnyx portal'],
            ],
            'plivo' => [
                ['key' => 'webhook_url', 'label' => 'Webhook URL', 'placeholder' => 'https://yourdomain.com/api/webhook/plivo'],
            ],
            'vonage' => [
                ['key' => 'webhook_url', 'label' => 'Webhook URL', 'placeholder' => 'https://yourdomain.com/api/webhook/vonage'],
            ],
            default => [],
        };
    }

    /* ── Provider types that can be added ── */
    public static function availableTypes(): array
    {
        return [
            ['type' => 'twilio',        'name' => 'Twilio',        'description' => 'SMS & Voice provider. Fetch countries, numbers & pricing in real-time.', 'capabilities' => ['countries', 'numbers', 'pricing']],
            ['type' => 'telnyx',        'name' => 'Telnyx',        'description' => 'Programmable phone numbers with global coverage.',                       'capabilities' => ['countries', 'numbers', 'pricing']],
            ['type' => 'plivo',         'name' => 'Plivo',         'description' => 'Cloud communications platform with SMS and voice.',                      'capabilities' => ['countries', 'numbers', 'pricing']],
            ['type' => 'vonage',        'name' => 'Vonage',        'description' => 'Communication APIs for SMS, voice and phone verification.',              'capabilities' => ['countries', 'numbers', 'pricing']],
            ['type' => '5sim',          'name' => '5SIM',          'description' => 'Budget SMS verification provider.',                                      'capabilities' => ['countries', 'numbers', 'pricing']],
            ['type' => 'smspva',        'name' => 'SMSPVA',        'description' => 'SMS verification provider.',                                             'capabilities' => ['countries', 'numbers', 'pricing']],
            ['type' => 'sms_activate',  'name' => 'SMS-Activate',  'description' => 'Virtual numbers for SMS verification.',                                  'capabilities' => ['countries', 'numbers', 'pricing']],
            ['type' => 'smspool',       'name' => 'SMSPool',        'description' => 'Budget SMS verification with global coverage. Fallback provider.',      'capabilities' => ['countries', 'numbers', 'pricing']],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  ROUTING HELPERS
    // ═══════════════════════════════════════════════════════════════

    /* ── Relationship: orders allocated through this provider ── */
    public function orders()
    {
        return $this->hasMany(NumberOrder::class, 'provider_id');
    }

    /* ── Smart routing score (higher = better) ── */
    public function getSmartScore(): float
    {
        $sr = $this->success_rate ?: 50;
        $cm = $this->cost_multiplier ?: 1;
        $pr = $this->priority ?: 10;
        return ($sr * 0.6) + ((100 / $cm) * 0.2) + ((100 / $pr) * 0.2);
    }

    /* ── Reset routing metrics ── */
    public function resetMetrics(): void
    {
        $this->update([
            'success_rate'    => 100,
            'avg_response_ms' => 0,
            'total_requests'  => 0,
            'total_successes' => 0,
            'total_failures'  => 0,
        ]);
    }
}
