<?php

namespace App\Http\Controllers;

use App\Models\ApiSetting;
use App\Models\Country;
use App\Models\NumberOrder;
use App\Models\Service;
use App\Services\ProviderRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * Create a new number order — smart-routes through available providers.
     *
     * Security:
     *  - Rate limited: max 3 orders per user per minute
     *  - Minimum wallet balance enforced
     *  - Atomic: wallet deduct + order create in DB::transaction
     *  - Number provisioned BEFORE wallet deduction
     *  - Auto-rollback: release number if DB fails
     *
     * Routing:
     *  The ProviderRouter handles provider selection, failover, and metrics.
     *  Users never see which provider was used — they only see the number.
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

        if ($wallet->total_balance < $cost) {
            return response()->json([
                'message'  => 'Insufficient wallet balance. Please fund your wallet to continue.',
                'required' => $cost,
                'balance'  => $wallet->total_balance,
            ], 422);
        }

        // ─── 1. Provision number via Smart Router (BEFORE wallet deduction) ───
        $router = new ProviderRouter();

        try {
            $allocation = $router->allocateNumber($country, $service->slug ?? null);
        } catch (\Exception $e) {
            \Log::error('ProviderRouter allocation failed', [
                'user_id'    => $user->id,
                'service_id' => $service->id,
                'country_id' => $country->id,
                'ip'         => $request->ip(),
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'message' => $e->getMessage(),
            ], 502);
        }

        // ─── 2. Atomic: deduct wallet + create order ───
        try {
            $order = DB::transaction(function () use (
                $user, $wallet, $cost, $service, $country,
                $allocation, $request
            ) {
                // Re-check balance inside transaction (prevents race condition)
                $freshWallet = $user->wallet()->lockForUpdate()->first();
                if ($freshWallet->total_balance < $cost) {
                    throw new \Exception('Insufficient wallet balance.');
                }

                // Deduct wallet
                $freshWallet->deduct($cost, "Number rental: {$service->name} ({$country->name})", [
                    'service_id' => $service->id,
                    'country_id' => $country->id,
                ]);

                // Create order with provider tracking
                $order = NumberOrder::create([
                    'user_id'              => $user->id,
                    'service_id'           => $service->id,
                    'country_id'           => $country->id,
                    'order_ref'            => 'ORD-' . strtoupper(Str::random(8)),
                    'phone_number'         => $allocation['phone_number'],
                    'twilio_sid'           => $allocation['provider_sid'],  // kept for backward compat
                    'status'               => 'pending',
                    'cost'                 => $cost,
                    'expires_at'           => now()->addMinutes((int) env('NUMBER_EXPIRY_MINUTES', 5)),
                    'ip_address'           => $request->ip(),
                    'user_agent'           => $request->userAgent(),
                    // Provider routing metadata
                    'provider_id'          => $allocation['provider_id'],
                    'provider_slug'        => $allocation['provider_slug'],
                    'provider_response_ms' => $allocation['response_ms'],
                    'retry_count'          => $allocation['retry_count'],
                    'routing_log'          => $allocation['routing_log'],
                ]);

                return $order;
            });
        } catch (\Exception $e) {
            // Rollback: release the provisioned number since DB transaction failed
            try {
                $router->releaseNumber(
                    $allocation['provider_sid'],
                    $allocation['provider_id'],
                    $allocation['provider_slug']
                );
                \Log::info("Released number {$allocation['phone_number']} after DB failure.");
            } catch (\Exception $releaseEx) {
                \Log::error("Failed to release number {$allocation['phone_number']}: " . $releaseEx->getMessage());
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $order->load(['service:id,name,color,icon', 'country:id,name,flag,dial_code']);

        return response()->json([
            'message'        => 'Number successfully provisioned. Waiting for OTP…',
            'order'          => $order,
            'wallet_balance' => $wallet->fresh()->total_balance,
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
            $this->releaseInternalNumber($order);
            $this->refundOrder($order, $request->user());
        }

        $order->load(['service:id,name,color,icon', 'country:id,name,flag,dial_code']);

        return response()->json($order);
    }

    /**
     * Cancel a pending order — refunds wallet and releases number.
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
        $this->releaseInternalNumber($order);

        // Refund wallet
        $this->refundOrder($order, $request->user());

        $order->update(['status' => 'cancelled']);

        return response()->json([
            'message'        => 'Order cancelled and wallet refunded.',
            'wallet_balance' => $request->user()->wallet?->fresh()?->total_balance,
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Release a number back to its provider using the smart router.
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
            \Log::info("Released number {$order->phone_number} (order #{$order->id}) via provider {$order->provider_slug}");
        } catch (\Exception $e) {
            \Log::warning("Release error for order #{$order->id}: " . $e->getMessage());
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

    /**
     * Release an internal pool number back to 'available' status.
     */
    private function releaseInternalNumber(NumberOrder $order): void
    {
        if ($order->provider_slug !== 'internal') return;

        $phoneNumber = \App\Models\PhoneNumber::where('phone_number', $order->phone_number)
            ->where('status', 'in_use')
            ->first();

        if ($phoneNumber) {
            $phoneNumber->release();
            \Log::info("Internal pool number {$order->phone_number} released back to pool (order #{$order->id}).");
        }
    }
}
