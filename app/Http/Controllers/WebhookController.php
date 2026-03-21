<?php

namespace App\Http\Controllers;

use App\Models\ApiProvider;
use App\Models\ApiSetting;
use App\Models\NumberOrder;
use App\Models\PhoneNumber;
use App\Services\ProviderRouter;
use Illuminate\Http\Request;
use Twilio\Security\RequestValidator;

class WebhookController extends Controller
{
    /**
     * Incoming SMS webhook — handles Twilio (X-Twilio-Signature).
     *
     * Flow:
     *  1. Validate signature (Twilio X-Twilio-Signature)
     *  2. Match incoming SMS to a pending order by phone_number
     *  3. Save OTP, mark completed, release number via ProviderRouter
     *  4. If expired, refund wallet and release
     */
    public function sms(Request $request)
    {
        // ─── 1. Validate Twilio Signature ───
        // Try provider-specific token first, then legacy ApiSetting, then env
        $twilioToken = $this->resolveTwilioAuthToken();

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

        // ─── 2. Extract fields ───
        $to     = $request->input('To');      // Our provisioned number (E.164)
        $from   = $request->input('From');    // Sender's number
        $body   = $request->input('Body');    // Full SMS body (contains OTP)
        $msgSid = $request->input('MessageSid');

        \Log::info("SMS webhook received", [
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
                    'otp_code'     => $body,
                    'status'       => 'completed',
                    'sms_from'     => $from,
                    'completed_at' => now(),
                ]);

                \Log::info("OTP saved for order #{$order->id}: {$body}");

                // Track success on the provider
                if ($order->provider_id) {
                    $provider = ApiProvider::find($order->provider_id);
                    if ($provider) {
                        $provider->increment('total_successes');
                    }
                }

                // Auto-release number via ProviderRouter
                $this->releaseNumber($order);

                // Release internal pool number back to available
                $this->releaseInternalNumber($order);

            } else {
                // Expired — mark it and release
                $order->update(['status' => 'expired']);
                $this->releaseNumber($order);
                $this->releaseInternalNumber($order);

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
     * Resolve Twilio auth token — checks active providers, then legacy settings, then env.
     */
    private function resolveTwilioAuthToken(): ?string
    {
        // Try first active Twilio provider
        $twilioProvider = ApiProvider::where('type', 'twilio')
            ->where('is_active', true)
            ->first();

        if ($twilioProvider) {
            $token = $twilioProvider->getCredential('auth_token');
            if ($token) return $token;
        }

        // Fall back to legacy ApiSetting
        $legacyToken = ApiSetting::getValue('twilio_auth_token');
        if ($legacyToken) return $legacyToken;

        // Fall back to env
        return env('TWILIO_AUTH_TOKEN');
    }

    /**
     * Release a provisioned number via ProviderRouter (multi-provider aware).
     */
    private function releaseNumber(NumberOrder $order): void
    {
        if (!$order->twilio_sid) return;

        try {
            $router = new ProviderRouter();
            $router->releaseNumber(
                $order->twilio_sid,
                $order->provider_id,
                $order->provider_slug
            );

            $order->update(['twilio_sid' => null]); // Mark as released
            \Log::info("Auto-released number {$order->phone_number} (order #{$order->id}) via provider {$order->provider_slug}");
        } catch (\Exception $e) {
            \Log::error("Failed to release number for order #{$order->id}: " . $e->getMessage());
        }
    }

    /**
     * Release an internal pool number back to 'available' status.
     */
    private function releaseInternalNumber(NumberOrder $order): void
    {
        if ($order->provider_slug !== 'internal') return;

        $phoneNumber = PhoneNumber::where('phone_number', $order->phone_number)
            ->where('status', 'in_use')
            ->first();

        if ($phoneNumber) {
            $phoneNumber->release();
            \Log::info("Internal pool number {$order->phone_number} released back to pool.");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  TELNYX WEBHOOK
    // ═══════════════════════════════════════════════════════════════

    /**
     * Incoming SMS webhook from Telnyx.
     *
     * Telnyx sends a POST with JSON body:
     *   data.event_type = "message.received"
     *   data.payload.to[0].phone_number = our number (E.164)
     *   data.payload.from.phone_number  = sender's number
     *   data.payload.text               = SMS body (OTP)
     *
     * Optional: Verify telnyx-signature-ed25519 header with public key.
     */
    public function telnyxSms(Request $request)
    {
        // ─── 1. Validate signature (optional — requires public key) ───
        $this->validateTelnyxSignature($request);

        // ─── 2. Parse event ───
        $eventType = $request->input('data.event_type');

        // Only process inbound messages
        if ($eventType !== 'message.received') {
            \Log::info("Telnyx webhook: ignoring event type {$eventType}");
            return response()->json(['status' => 'ignored'], 200);
        }

        $payload = $request->input('data.payload', []);
        $to      = $payload['to'][0]['phone_number'] ?? null;  // Our provisioned number
        $from    = $payload['from']['phone_number'] ?? null;    // Sender's number
        $body    = $payload['text'] ?? '';                       // SMS body (contains OTP)
        $msgId   = $payload['id'] ?? null;

        \Log::info("Telnyx SMS webhook received", [
            'to'       => $to,
            'from'     => $from,
            'msg_id'   => $msgId,
            'body'     => $body,
            'event'    => $eventType,
            'ip'       => $request->ip(),
        ]);

        if (!$to || !$body) {
            return response()->json(['status' => 'missing_data'], 200);
        }

        // ─── 3. Find matching pending order ───
        $order = NumberOrder::where('phone_number', $to)
            ->where('status', 'pending')
            ->first();

        if ($order) {
            if (!$order->isExpired()) {
                // Save OTP and mark completed
                $order->update([
                    'otp_code'     => $body,
                    'status'       => 'completed',
                    'sms_from'     => $from,
                    'completed_at' => now(),
                ]);

                \Log::info("Telnyx OTP saved for order #{$order->id}: {$body}");

                // Track success on the provider
                if ($order->provider_id) {
                    $provider = ApiProvider::find($order->provider_id);
                    if ($provider) {
                        $provider->increment('total_successes');
                    }
                }

                // Auto-release number via ProviderRouter
                $this->releaseNumber($order);

                // Release internal pool number back to available
                $this->releaseInternalNumber($order);

            } else {
                // Expired — mark it and release
                $order->update(['status' => 'expired']);
                $this->releaseNumber($order);
                $this->releaseInternalNumber($order);

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
        } else {
            \Log::info("Telnyx: No pending order for number {$to}");
        }

        // Respond with 200 so Telnyx doesn't retry
        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Validate Telnyx webhook signature (Ed25519).
     *
     * Uses the public key from the active Telnyx provider settings.
     * If no public key is configured, skip validation (dev mode).
     */
    private function validateTelnyxSignature(Request $request): void
    {
        // Get public key from active Telnyx provider
        $telnyxProvider = ApiProvider::where('type', 'telnyx')
            ->where('is_active', true)
            ->first();

        $publicKey = null;
        if ($telnyxProvider) {
            $publicKey = $telnyxProvider->getSetting('public_key')
                ?? $telnyxProvider->getCredential('public_key');
        }

        // Fall back to env
        if (!$publicKey) {
            $publicKey = env('TELNYX_PUBLIC_KEY');
        }

        // If no public key configured, skip validation (log a warning)
        if (!$publicKey) {
            \Log::warning('Telnyx webhook: No public key configured — skipping signature verification');
            return;
        }

        $signature = $request->header('telnyx-signature-ed25519');
        $timestamp = $request->header('telnyx-timestamp');

        if (!$signature || !$timestamp) {
            \Log::warning('Telnyx webhook: Missing signature or timestamp headers', [
                'ip' => $request->ip(),
            ]);
            abort(403, 'Missing signature headers');
        }

        // Check timestamp freshness (reject if older than 5 minutes)
        if (abs(time() - (int) $timestamp) > 300) {
            \Log::warning('Telnyx webhook: Timestamp too old', ['timestamp' => $timestamp]);
            abort(403, 'Webhook timestamp expired');
        }

        // Verify Ed25519 signature: signed content is "{timestamp}|{payload}"
        $payload = $request->getContent();
        $signedPayload = "{$timestamp}|{$payload}";

        try {
            $decodedSignature = base64_decode($signature);
            $decodedPublicKey = base64_decode($publicKey);

            $valid = sodium_crypto_sign_verify_detached(
                $decodedSignature,
                $signedPayload,
                $decodedPublicKey
            );

            if (!$valid) {
                \Log::warning('Telnyx webhook: Invalid signature', ['ip' => $request->ip()]);
                abort(403, 'Invalid signature');
            }
        } catch (\Exception $e) {
            \Log::warning('Telnyx webhook: Signature verification error: ' . $e->getMessage());
            // In development, allow through; in production you may want to abort
            if (app()->environment('production')) {
                abort(403, 'Signature verification failed');
            }
        }
    }
}
