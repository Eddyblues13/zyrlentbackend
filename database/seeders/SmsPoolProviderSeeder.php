<?php

namespace Database\Seeders;

use App\Models\ApiProvider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Registers SMSPool as a secondary (fallback) SMS provider.
 *
 * Priority: SMSPool uses priority=20, which means it is tried AFTER
 * 5SIM (priority=10). If 5SIM fails for any reason (no numbers,
 * balance insufficient, timeout), the ProviderRouter automatically
 * falls over to SMSPool.
 *
 * After running this seeder:
 *   1. Set SMSPOOL_API_KEY in your .env
 *   2. Run: php artisan db:seed --class=SmsPoolProviderSeeder
 *      (safe to re-run — uses updateOrCreate)
 *   3. Optionally activate/deactivate via the Admin → API Providers UI.
 */
class SmsPoolProviderSeeder extends Seeder
{
    public function run(): void
    {
        $apiKey = env('SMSPOOL_API_KEY', '');

        $hasKey = ! empty(trim($apiKey));

        /** @var ApiProvider $provider */
        $provider = ApiProvider::updateOrCreate(
            ['slug' => 'smspool'],
            [
                'name' => 'SMSPool',
                'type' => 'smspool',
                'description' => 'SMSPool.net — virtual SMS number provider. Used as a fallback when 5SIM has no available numbers.',
                'is_active' => $hasKey,   // Only activate when an API key is present
                'priority' => 20,        // Higher number = lower priority (5SIM uses 10)
                'cost_multiplier' => 1.00,
                'markup_percent' => 0.00,
                'capabilities' => ['sms', 'activation'],
            ]
        );

        // Only set credentials if we actually have an API key; otherwise leave empty
        // so the admin can add it later through the UI without losing other settings.
        if ($hasKey) {
            $provider->credentials = ['api_key' => $apiKey];
            $provider->save();
            $this->command->info('✓ SMSPool provider created/updated with API key — is_active=true, priority=20.');
            Log::info('SmsPoolProviderSeeder: SMSPool provider activated with API key.');
        } else {
            $this->command->warn(
                '⚠  SMSPool provider created but SMSPOOL_API_KEY is not set in .env.'.PHP_EOL.
                '   Add your key to .env and re-run this seeder, or set the credentials through the Admin panel.'
            );
            Log::warning('SmsPoolProviderSeeder: SMSPool provider created without API key — is_active=false.');
        }

        $this->command->line("   ID={$provider->id}, slug={$provider->slug}, priority={$provider->priority}, active=".($provider->is_active ? 'true' : 'false'));
    }
}
