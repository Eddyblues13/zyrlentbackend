<?php

namespace App\Console\Commands;

use App\Models\ApiSetting;
use App\Models\NumberOrder;
use Illuminate\Console\Command;
use Twilio\Rest\Client as TwilioClient;

class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';
    protected $description = 'Expire pending orders past their expiry time, release Twilio numbers, and refund wallets';

    public function handle()
    {
        $expiredOrders = NumberOrder::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->get();

        if ($expiredOrders->isEmpty()) {
            $this->info('No expired orders found.');
            return 0;
        }

        $twilioSid   = ApiSetting::getValue('twilio_account_sid', env('TWILIO_ACCOUNT_SID'));
        $twilioToken = ApiSetting::getValue('twilio_auth_token', env('TWILIO_AUTH_TOKEN'));
        $twilio      = null;

        if ($twilioSid && $twilioToken) {
            try {
                $twilio = new TwilioClient($twilioSid, $twilioToken);
            } catch (\Exception $e) {
                $this->error('Failed to create Twilio client: ' . $e->getMessage());
            }
        }

        $count = 0;

        foreach ($expiredOrders as $order) {
            // 1. Release Twilio number
            if ($order->twilio_sid && $twilio) {
                try {
                    $twilio->incomingPhoneNumbers($order->twilio_sid)->delete();
                    $this->line("  Released: {$order->phone_number}");
                } catch (\Exception $e) {
                    $this->warn("  Failed to release {$order->phone_number}: " . $e->getMessage());
                }
            }

            // 2. Refund wallet
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
                'status'     => 'expired',
                'twilio_sid' => null,
            ]);

            $count++;
        }

        $this->info("Expired {$count} orders.");
        return 0;
    }
}
