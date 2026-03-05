<?php

namespace App\Http\Controllers;

use App\Models\ApiSetting;
use App\Models\NumberOrder;
use Illuminate\Http\Request;
use Twilio\Rest\Client as TwilioClient;
use Twilio\Security\RequestValidator;

class WebhookController extends Controller
{
    /**
     * Twilio posts here when an SMS is received on any of our provisioned numbers.
     *
     * Security:
     *  - Validates X-Twilio-Signature to prevent spoofed webhooks
     *  - Logs IP + user agent for audit trail
     *  - Auto-releases Twilio number after OTP is saved
     */
    public function sms(Request $request)
    {
        // ─── 1. Validate Twilio Signature ───
        $twilioToken = ApiSetting::getValue('twilio_auth_token', env('TWILIO_AUTH_TOKEN'));

        if ($twilioToken) {
            $validator = new RequestValidator($twilioToken);
            $signature = $request->header('X-Twilio-Signature', '');
            $url       = $request->fullUrl();
            $params    = $request->all();

            if (!$validator->validate($signature, $url, $params)) {
                \Log::warning('Webhook signature validation failed', [
                    'ip'        => $request->ip(),
                    'url'       => $url,
                    'signature' => $signature,
                ]);
                return response('<Response/>', 403)->header('Content-Type', 'text/xml');
            }
        }

        // ─── 2. Extract Twilio fields ───
        $to     = $request->input('To');      // Our provisioned number (E.164)
        $from   = $request->input('From');    // Sender's number
        $body   = $request->input('Body');    // Full SMS body (contains OTP)
        $msgSid = $request->input('MessageSid');

        \Log::info("Twilio SMS webhook received", [
            'to'     => $to,
            'from'   => $from,
            'sid'    => $msgSid,
            'body'   => $body,
            'ip'     => $request->ip(),
        ]);

        if (!$to || !$body) {
            return response('<Response/>', 200)->header('Content-Type', 'text/xml');
        }

        // ─── 3. Find matching pending order ───
        $order = NumberOrder::where('phone_number', $to)
            ->where('status', 'pending')
            ->first();

        if ($order) {
            if (!$order->isExpired()) {
                // Save OTP and mark completed
                $order->update([
                    'otp_code'   => $body,
                    'status'     => 'completed',
                    'sms_from'   => $from,
                    'completed_at' => now(),
                ]);

                \Log::info("OTP saved for order #{$order->id}: {$body}");

                // ─── 4. Auto-release Twilio number after OTP received ───
                $this->releaseNumber($order);

            } else {
                // Expired — mark it and release
                $order->update(['status' => 'expired']);
                $this->releaseNumber($order);

                // Refund wallet
                $user = $order->user;
                if ($user) {
                    $wallet = $user->wallet;
                    if ($wallet && $order->cost > 0) {
                        $wallet->credit((float) $order->cost, "Refund: expired order {$order->order_ref}", [
                            'order_id' => $order->id,
                        ]);
                    }
                }
            }
        }

        // Respond with empty TwiML so Twilio doesn't retry
        return response('<Response/>', 200)->header('Content-Type', 'text/xml');
    }

    /**
     * Release a Twilio number.
     */
    private function releaseNumber(NumberOrder $order): void
    {
        if (!$order->twilio_sid) return;

        try {
            $sid   = ApiSetting::getValue('twilio_account_sid', env('TWILIO_ACCOUNT_SID'));
            $token = ApiSetting::getValue('twilio_auth_token', env('TWILIO_AUTH_TOKEN'));
            $twilio = new TwilioClient($sid, $token);
            $twilio->incomingPhoneNumbers($order->twilio_sid)->delete();

            $order->update(['twilio_sid' => null]); // Mark as released
            \Log::info("Auto-released Twilio number {$order->phone_number} (order #{$order->id})");
        } catch (\Exception $e) {
            \Log::error("Failed to release Twilio number for order #{$order->id}: " . $e->getMessage());
        }
    }
}
