<?php

namespace Database\Seeders;

use App\Models\ApiProvider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Registers 5SIM as the primary SMS provider.
 *
 * Priority=10 ensures 5SIM is always tried before SMSPool (priority=20).
 * The ProviderRouter sorts providers by ascending priority, so lower number = tried first.
 *
 * After running this seeder:
 *   1. Set FIVESIM_API_KEY in your .env
 *   2. Run: php artisan db:seed --class=FiveSimProviderSeeder
 *      (safe to re-run — uses updateOrCreate)
 */
class FiveSimProviderSeeder extends Seeder
{
    public function run(): void
    {
        $apiKey = env('FIVESIM_API_KEY', '');

        $hasKey = ! empty(trim($apiKey));

        /** @var ApiProvider $provider */
        $provider = ApiProvider::updateOrCreate(
            ['slug' => '5sim'],
            [
                'name' => '5SIM',
                'type' => '5sim',
                'description' => '5sim.net — virtual phone number provider. Primary provider for SMS activations.',
                'is_active' => $hasKey,
                'priority' => 10,        // Lower number = higher priority (tried before SMSPool)
                'cost_multiplier' => 1.00,
                'markup_percent' => 0.00,
                'capabilities' => ['sms', 'activation'],
            ]
        );

        if ($hasKey) {
            $provider->credentials = ['api_key' => $apiKey];
            $provider->save();
            $this->command->info('✓ 5SIM provider created/updated with API key — is_active=true, priority=10.');
            Log::info('FiveSimProviderSeeder: 5SIM provider activated with API key.');
        } else {
            $this->command->warn(
                '⚠  5SIM provider created but FIVESIM_API_KEY is not set in .env.'.PHP_EOL.
                '   Add your key to .env and re-run this seeder, or set the credentials through the Admin panel.'
            );
        }

        $this->command->line("   ID={$provider->id}, slug={$provider->slug}, priority={$provider->priority}, active=".($provider->is_active ? 'true' : 'false'));
    }
}
