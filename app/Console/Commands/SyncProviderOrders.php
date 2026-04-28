<?php

namespace App\Console\Commands;

use App\Events\OtpReceived;
use App\Models\ApiProvider;
use App\Models\NumberOrder;
use App\Services\FiveSimService;
use App\Services\SmsPoolService;
use App\Services\ProviderRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncProviderOrders extends Command
{
    protected $signature = 'orders:sync-provider';
    protected $description = 'Poll 5sim, SMSPool, and other providers for OTP codes and status changes on pending orders';

    public function handle(): int
    {
        $pendingOrders = NumberOrder::where('status', 'pending')
            ->whereIn('provider_slug', ['5sim', 'smspool'])
            ->whereNotNull('provider_order_id')
            ->where('expires_at', '>', now()) // Skip orders that are about to be expired by orders:expire
            ->with(['user.wallet', 'provider'])
            ->get();

        if ($pendingOrders->isEmpty()) {
            $this->info('No pending provider orders to sync.');
            return 0;
        }

        $this->info("Syncing {$pendingOrders->count()} pending provider orders...");

        $synced = 0;

        foreach ($pendingOrders as $order) {
            try {
                if ($order->provider_slug === 'smspool') {
                    $this->syncSmsPoolOrder($order);
                } else {
                    $this->syncOrder($order);
                }
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

            // 🔔 Broadcast instantly to frontend via Reverb websocket
            try {
                $order->refresh();
                OtpReceived::dispatch($order);
            } catch (\Exception $broadcastEx) {
                Log::warning("SyncProviderOrders: OtpReceived broadcast failed for order #{$order->id}: {$broadcastEx->getMessage()}");
            }

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

    /**
     * Sync an SMSPool order — check for received SMS.
     */
    private function syncSmsPoolOrder(NumberOrder $order): void
    {
        $router = new ProviderRouter();
        $smsPoolData = $router->checkSmsPoolOrder(
            $order->provider_order_id,
            $order->provider_id
        );

        if (!$smsPoolData) {
            return;
        }

        $statusCode = (int) ($smsPoolData['status'] ?? 0);
        $statusName = SmsPoolService::mapStatusCode($statusCode);
        $smsCode = $smsPoolData['code'] ?? '';
        $smsText = $smsPoolData['sms'] ?? $smsPoolData['full_sms'] ?? '';

        // SMS received (status 3 = Completed)
        if ($statusCode === 3 && ($smsCode || $smsText)) {
            $otpCode = $smsCode ?: $smsText;

            $order->update([
                'otp_code'     => $otpCode,
                'sms_from'     => 'SMSPool',
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            Log::info("SyncProviderOrders: SMSPool OTP received for order #{$order->id}: code={$otpCode}");
            $this->line("  ✓ Order #{$order->id}: SMSPool OTP received — {$otpCode}");

            // Broadcast instantly to frontend via Reverb websocket
            try {
                $order->refresh();
                OtpReceived::dispatch($order);
            } catch (\Exception $broadcastEx) {
                Log::warning("SyncProviderOrders: OtpReceived broadcast failed for order #{$order->id}: {$broadcastEx->getMessage()}");
            }

            // Track provider success
            if ($order->provider) {
                $order->provider->increment('total_successes');
            }

            return;
        }

        // Expired on provider side (status 2)
        if ($statusCode === 2) {
            $order->update(['status' => 'expired']);
            $this->refundOrder($order);
            Log::info("SyncProviderOrders: SMSPool order #{$order->id} expired");
            $this->line("  ⏰ Order #{$order->id}: SMSPool expired — refunded");
            return;
        }

        // Cancelled or refunded on provider side (status 5 or 6)
        if (in_array($statusCode, [5, 6])) {
            $order->update(['status' => 'cancelled']);
            $this->refundOrder($order);
            Log::info("SyncProviderOrders: SMSPool order #{$order->id} cancelled/refunded (status: {$statusName})");
            $this->line("  ✗ Order #{$order->id}: SMSPool cancelled — refunded");
            return;
        }

        // Still pending — no action needed
        $this->line("  … Order #{$order->id}: Still pending (SMSPool status: {$statusName})");
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
