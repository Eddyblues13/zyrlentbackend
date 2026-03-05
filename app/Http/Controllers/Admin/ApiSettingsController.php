<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiSetting;
use Illuminate\Http\Request;

class ApiSettingsController extends Controller
{
    /**
     * Return all API settings (values masked for security).
     */
    public function index()
    {
        $keys = [
            'twilio_account_sid',
            'twilio_auth_token',
            'twilio_webhook_secret',
            'twilio_phone_number',
        ];

        $settings = [];
        foreach ($keys as $key) {
            $value = ApiSetting::getValue($key);
            $settings[$key] = [
                'value' => $value ? $this->mask($value) : null,
                'is_set' => !empty($value),
            ];
        }

        return response()->json($settings);
    }

    /**
     * Update API settings.
     */
    public function update(Request $request)
    {
        $request->validate([
            'twilio_account_sid' => 'nullable|string|max:255',
            'twilio_auth_token' => 'nullable|string|max:255',
            'twilio_webhook_secret' => 'nullable|string|max:255',
            'twilio_phone_number' => 'nullable|string|max:50',
        ]);

        $updated = 0;
        foreach ($request->only(['twilio_account_sid', 'twilio_auth_token', 'twilio_webhook_secret', 'twilio_phone_number']) as $key => $value) {
            if ($value !== null && $value !== '') {
                ApiSetting::setValue($key, $value);
                $updated++;
            }
        }

        return response()->json([
            'message' => "{$updated} setting(s) updated successfully.",
        ]);
    }

    private function mask(string $value): string
    {
        if (strlen($value) <= 6) return '••••••';
        return substr($value, 0, 3) . str_repeat('•', max(0, strlen($value) - 6)) . substr($value, -3);
    }
}
