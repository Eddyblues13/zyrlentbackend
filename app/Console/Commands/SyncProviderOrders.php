<?php

namespace App\Console\Commands;

use App\Models\ApiProvider;
use App\Models\NumberOrder;
use App\Services\FiveSimService;
use App\Services\ProviderRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncProviderOrders extends Command
{
    protected $signature = 'orders:sync-provider';
    protected $description = 'Poll 5sim (and other providers) for OTP codes and status changes on pending orders';

    public function handle(): int
    {
        $pendingOrders = NumberOrder::where('status', 'pending')
            ->where('provider_slug', '5sim')
            ->whereNotNull('provider_order_id')
            ->with(['user.wallet', 'provider'])
            ->get();

        if ($pendingOrders->isEmpty()) {
            $this->info('No pending 5sim orders to sync.');
            return 0;
        }

        $this->info("Syncing {$pendingOrders->count()} pending 5sim orders...");

        $synced = 0;

        foreach ($pendingOrders as $order) {
            try {
                $this->syncOrder($order);
                $synced++;
            } catch (\Exception $e) {
                Log::warning("SyncProviderOrders: Failed to sync order #{$order->id}: {$e->getMessage()}");
                $this->warn("  ✗ Order #{$order->id}: {$e->getMessage()}");
            }

            // Small delay to avoid hammering the API
            usleep(300_000); // 300ms
        }

        $this->info("Synced {$synced}/{$pendingOrders->count()} orders.");
        return 0;
    }

    private function syncOrder(NumberOrder $order): void
    {
        $router = new ProviderRouter();
        $fiveSimData = $router->check5SimOrder(
            $order->provider_order_id,
            $order->provider_id
        );

        if (!$fiveSimData) {
            return;
        }

        $fiveSimStatus = $fiveSimData['status'] ?? '';
        $smsArray = $fiveSimData['sms'] ?? [];

        // SMS received — update OTP code and mark completed
        if (!empty($smsArray) && in_array($fiveSimStatus, ['RECEIVED', 'FINISHED'])) {
            $lastSms = end($smsArray);
            $smsText = $lastSms['text'] ?? '';
            $smsCode = $lastSms['code'] ?? '';
            $smsSender = $lastSms['sender'] ?? '';
            $otpCode = $smsCode ?: $smsText;

            $order->update([
                'otp_code'     => $otpCode,
                'sms_from'     => $smsSender,
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            Log::info("SyncProviderOrders: OTP received for order #{$order->id}: code={$otpCode}");
            $this->line("  ✓ Order #{$order->id}: OTP received — {$otpCode}");

            // Track provider success
            if ($order->provider) {
                $order->provider->increment('total_successes');
            }

            // Finish order on 5sim side
            try {
                if ($order->provider) {
                    $fiveSim = FiveSimService::fromProvider($order->provider);
                    $fiveSim->finishOrder((int) $order->provider_order_id);
                }
            } catch (\Exception $e) {
                Log::warning("SyncProviderOrders: Failed to finish 5sim order: {$e->getMessage()}");
            }

            return;
        }

        // Timed out on provider side
        if ($fiveSimStatus === 'TIMEOUT') {
            $order->update(['status' => 'expired']);
            $this->refundOrder($order);
            Log::info("SyncProviderOrders: Order #{$order->id} timed out on 5sim");
            $this->line("  ⏰ Order #{$order->id}: Timed out — refunded");
            return;
        }

        // Cancelled or banned on provider side
        if (in_array($fiveSimStatus, ['CANCELED', 'BANNED'])) {
            $order->update(['status' => 'cancelled']);
            $this->refundOrder($order);
            Log::info("SyncProviderOrders: Order #{$order->id} cancelled on 5sim (status: {$fiveSimStatus})");
            $this->line("  ✗ Order #{$order->id}: Cancelled by provider — refunded");
            return;
        }

        // Still pending — no action needed
        $this->line("  … Order #{$order->id}: Still pending (5sim status: {$fiveSimStatus})");
    }

    private function refundOrder(NumberOrder $order): void
    {
        $user = $order->user;
        if (!$user) {
            return;
        }

        $wallet = $user->wallet;
        if ($wallet && $order->cost > 0) {
            $wallet->credit((float) $order->cost, "Refund: {$order->order_ref}", [
                'order_id' => $order->id,
            ]);
        }
    }
}
