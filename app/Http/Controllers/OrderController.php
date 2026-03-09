<?php

namespace App\Http\Controllers;

use App\Models\ApiSetting;
use App\Models\Country;
use App\Models\NumberOrder;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Twilio\Rest\Client as TwilioClient;

class OrderController extends Controller
{
    /**
     * List orders (purchase history) for the authenticated user — paginated.
     */
    public function index(Request $request)
    {
        $query = $request->user()->orders()
            ->with(['service:id,name,color,icon', 'country:id,name,flag,dial_code'])
            ->latest();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_ref', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhereHas('service', fn($q2) => $q2->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('country', fn($q2) => $q2->where('name', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $orders = $query->paginate($request->get('per_page', 10));

        return response()->json($orders);
    }

    /**
     * Create a new number order — provisions a Twilio phone number.
     *
     * Security:
     *  - Rate limited: max 3 orders per user per minute
     *  - Minimum wallet balance enforced
     *  - Atomic: wallet deduct + order create in DB::transaction
     *  - Twilio purchased BEFORE wallet deduction
     *  - Auto-rollback: release Twilio number if DB fails
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'country_id' => 'required|exists:countries,id',
        ]);

        $user    = $request->user();
        $service = Service::findOrFail($validated['service_id']);
        $country = Country::findOrFail($validated['country_id']);
        $cost    = (float) $service->cost;

        // ─── Rate Limiting: max 3 orders per user per minute ───
        $recentOrders = NumberOrder::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        if ($recentOrders >= 3) {
            return response()->json([
                'message' => 'Too many orders. Please wait a minute before trying again.',
            ], 429);
        }

        // ─── Check service & country are active ───
        if (!$service->is_active) {
            return response()->json(['message' => 'This service is currently unavailable.'], 422);
        }
        if (!$country->is_active) {
            return response()->json(['message' => 'This country is currently unavailable.'], 422);
        }

        // ─── Load wallet and check balance ───
        $wallet = $user->wallet()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0.00]
        );

        if ($wallet->balance < $cost) {
            return response()->json([
                'message' => 'Insufficient wallet balance. Please fund your wallet to continue.',
                'required' => $cost,
                'balance'  => $wallet->balance,
            ], 422);
        }

        // ─── Get Twilio credentials (from admin settings → fallback to .env) ───
        $twilioSid   = ApiSetting::getValue('twilio_account_sid', env('TWILIO_ACCOUNT_SID'));
        $twilioToken = ApiSetting::getValue('twilio_auth_token', env('TWILIO_AUTH_TOKEN'));

        if (!$twilioSid || !$twilioToken) {
            return response()->json([
                'message' => 'SMS service is not configured. Please contact support.',
            ], 503);
        }

        // ─── 1. Provision number via Twilio (BEFORE wallet deduction) ───
        $phoneNumber  = null;
        $twilioNumSid = null;

        try {
            $twilio = new TwilioClient($twilioSid, $twilioToken);

            $availableNumbers = $twilio->availablePhoneNumbers($country->twilio_code)
                ->local->read(['smsEnabled' => true], 1);

            if (empty($availableNumbers)) {
                return response()->json([
                    'message' => 'No numbers available for the selected country right now. Please try again later.',
                ], 422);
            }

            $phoneNumber = $availableNumbers[0]->phoneNumber;

            // Buy the number and attach SMS webhook (only if URL is a public domain)
            $webhookUrl = env('TWILIO_WEBHOOK_URL', rtrim(env('APP_URL'), '/') . '/api/webhook/sms');
            $createParams = ['phoneNumber' => $phoneNumber];

            // Twilio rejects localhost/127.0.0.1 webhook URLs — only attach if public
            if ($webhookUrl && !preg_match('/(localhost|127\.0\.0\.\d+)/i', $webhookUrl)) {
                $createParams['smsUrl']    = $webhookUrl;
                $createParams['smsMethod'] = 'POST';
            }

            $purchased = $twilio->incomingPhoneNumbers->create($createParams);

            $twilioNumSid = $purchased->sid;

        } catch (\Exception $e) {
            \Log::error('Twilio provisioning error: ' . $e->getMessage(), [
                'user_id'    => $user->id,
                'service_id' => $service->id,
                'country_id' => $country->id,
                'ip'         => $request->ip(),
            ]);
            return response()->json([
                'message' => 'Failed to provision a number. Please try again.',
            ], 502);
        }

        // ─── 2. Atomic: deduct wallet + create order ───
        try {
            $order = DB::transaction(function () use ($user, $wallet, $cost, $service, $country, $phoneNumber, $twilioNumSid, $request) {
                // Re-check balance inside transaction (prevents race condition)
                $freshWallet = $user->wallet()->lockForUpdate()->first();

                if ($freshWallet->balance < $cost) {
                    throw new \Exception('Insufficient wallet balance.');
                }

                // Deduct wallet
                $freshWallet->deduct($cost, "Number rental: {$service->name} ({$country->name})", [
                    'service_id' => $service->id,
                    'country_id' => $country->id,
                ]);

                // Create order
                $order = NumberOrder::create([
                    'user_id'      => $user->id,
                    'service_id'   => $service->id,
                    'country_id'   => $country->id,
                    'order_ref'    => 'ORD-' . strtoupper(Str::random(8)),
                    'phone_number' => $phoneNumber,
                    'twilio_sid'   => $twilioNumSid,
                    'status'       => 'pending',
                    'cost'         => $cost,
                    'expires_at'   => now()->addMinutes((int) env('NUMBER_EXPIRY_MINUTES', 5)),
                    'ip_address'   => $request->ip(),
                    'user_agent'   => $request->userAgent(),
                ]);

                return $order;
            });
        } catch (\Exception $e) {
            // Rollback: release Twilio number since DB transaction failed
            try {
                $twilio = new TwilioClient($twilioSid, $twilioToken);
                $twilio->incomingPhoneNumbers($twilioNumSid)->delete();
                \Log::info("Released Twilio number {$phoneNumber} after DB failure.");
            } catch (\Exception $releaseEx) {
                \Log::error("Failed to release Twilio number {$phoneNumber}: " . $releaseEx->getMessage());
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $order->load(['service:id,name,color,icon', 'country:id,name,flag,dial_code']);

        return response()->json([
            'message' => 'Number successfully provisioned. Waiting for OTP…',
            'order'   => $order,
            'wallet_balance' => $wallet->fresh()->balance,
        ], 201);
    }

    /**
     * Get a single order — used for polling OTP status.
     */
    public function show(Request $request, NumberOrder $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        // Auto-expire if past due
        if ($order->status === 'pending' && $order->isExpired()) {
            $order->update(['status' => 'expired']);
            $this->releaseNumber($order);
            $this->refundOrder($order, $request->user());
        }

        $order->load(['service:id,name,color,icon', 'country:id,name,flag,dial_code']);

        return response()->json($order);
    }

    /**
     * Cancel a pending order — refunds wallet and releases Twilio number.
     */
    public function cancel(Request $request, NumberOrder $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (!in_array($order->status, ['pending'])) {
            return response()->json(['message' => 'Only pending orders can be cancelled.'], 422);
        }

        $this->releaseNumber($order);

        // Refund wallet
        $this->refundOrder($order, $request->user());

        $order->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Order cancelled and wallet refunded.',
            'wallet_balance' => $request->user()->wallet?->fresh()?->balance,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────

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
            \Log::info("Released Twilio number {$order->phone_number} (order #{$order->id})");
        } catch (\Exception $e) {
            \Log::warning("Twilio release error for order #{$order->id}: " . $e->getMessage());
        }
    }

    /**
     * Refund wallet for an order.
     */
    private function refundOrder(NumberOrder $order, $user): void
    {
        $wallet = $user->wallet;
        if ($wallet && $order->cost > 0) {
            $wallet->credit((float) $order->cost, "Refund: {$order->order_ref}", [
                'order_id' => $order->id,
            ]);
        }
    }
}
