<?php

namespace App\Console\Commands;

use App\Models\NumberOrder;
use App\Services\FiveSimService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';
    protected $description = 'Expire pending orders past their expiry time, cancel on 5sim, and refund wallets';

    public function handle(): int
    {
        $expiredOrders = NumberOrder::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->with(['user.wallet', 'provider'])
            ->get();

        if ($expiredOrders->isEmpty()) {
            $this->info('No expired orders found.');
            return 0;
        }

        $count = 0;

        foreach ($expiredOrders as $order) {
            // 1. Cancel order on 5sim (refunds 5sim balance)
            if ($order->provider_order_id && $order->provider) {
                try {
                    $fiveSim = FiveSimService::fromProvider($order->provider);
                    $fiveSim->cancelOrder((int) $order->provider_order_id);
                    $this->line("  Cancelled on 5sim: order #{$order->id}");
                } catch (\Exception $e) {
                    Log::warning("ExpireOrders: Failed to cancel 5sim order #{$order->id}: {$e->getMessage()}");
                    $this->warn("  Failed to cancel 5sim order #{$order->id}: {$e->getMessage()}");
                }
            }

            // 2. Refund user wallet
            $user = $order->user;
            if ($user && $order->cost > 0) {
                $wallet = $user->wallet;
                if ($wallet) {
                    $wallet->credit((float) $order->cost, "Refund: expired order {$order->order_ref}", [
                        'order_id' => $order->id,
                    ]);
                    $this->line("  Refunded ₦{$order->cost} to user #{$user->id}");
                }
            }

            // 3. Mark expired
            $order->update([
                'status' => 'expired',
            ]);

            $count++;
        }

        $this->info("Expired {$count} orders.");
        return 0;
    }
}
