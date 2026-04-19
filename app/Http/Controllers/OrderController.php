<?php

namespace App\Http\Controllers;

use App\Models\ApiProvider;
use App\Models\ApiSetting;
use App\Models\Country;
use App\Models\NumberOrder;
use App\Models\Service;
use App\Services\FiveSimService;
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
     * Calculate total price for a service+country combo.
     */
    public function calculatePrice(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'country_id' => 'required|exists:countries,id',
            'operator'   => 'nullable|string|max:50',
        ]);

        $service = Service::findOrFail($request->service_id);
        $country = Country::findOrFail($request->country_id);
        $operator = $request->input('operator', 'any');

        $total = $this->resolveDynamicPrice($service, $country, $operator);

        return response()->json([
            'total'    => $total,
            'currency' => 'NGN',
        ]);
    }

    /**
     * Create a new number order — smart-routes through available providers.
     *
     * For 5sim: buys an activation number and stores the 5sim order ID.
     * Frontend then polls GET /api/orders/{id} to check for SMS via 5sim API.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'country_id' => 'required|exists:countries,id',
            'operator' => 'nullable|string|max:50',
        ]);

        $user    = $request->user();
        $service = Service::findOrFail($validated['service_id']);
        $country = Country::findOrFail($validated['country_id']);

        // --- Calculate total cost: service price + country price ---
        
        $operator = $validated['operator'] ?? 'any';
        $cost = $this->resolveDynamicPrice($service, $country, $operator);

        if ($cost <= 0) {
            return response()->json(['message' => 'Pricing not configured for this combination. Please contact support.'], 422);
        }

        // --- Rate Limiting: max 3 orders per user per minute ---
        $recentOrders = NumberOrder::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        if ($recentOrders >= 3) {
            return response()->json([
                'message' => 'Too many orders. Please wait a minute before trying again.',
            ], 429);
        }

        // --- Check service & country are active ---
        if (!$service->is_active) {
            return response()->json(['message' => 'This service is currently unavailable.'], 422);
        }
        if (!$country->is_active) {
            return response()->json(['message' => 'This country is currently unavailable.'], 422);
        }

        // --- Load wallet and check balance ---
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

        // --- 1. Provision number via Smart Router (BEFORE wallet deduction) ---
        $router = new ProviderRouter();

        try {
            $operator = $validated["operator"] ?? "any";
            $allocation = $router->allocateNumber($country, $service->slug ?? null, $operator);
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

        // --- 2. Atomic: deduct wallet + create order ---
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
                    'twilio_sid'           => $allocation['provider_sid'],
                    'status'               => 'pending',
                    'cost'                 => $cost,
                    'expires_at'           => now()->addMinutes((int) env('NUMBER_EXPIRY_MINUTES', 20)),
                    'ip_address'           => $request->ip(),
                    'user_agent'           => $request->userAgent(),
                    // Provider routing metadata
                    'provider_id'          => $allocation['provider_id'],
                    'provider_slug'        => $allocation['provider_slug'],
                    'provider_order_id'    => $allocation['provider_order_id'] ?? null,
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
                    $allocation['provider_slug'],
                    $allocation['provider_order_id'] ?? null
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
     *
     * For 5sim orders: actively polls the 5sim API to check for SMS,
     * then updates the local order if SMS was received.
     */
    public function show(Request $request, NumberOrder $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $providerInfo = null;

        // IMPORTANT: Poll provider for SMS FIRST, before checking local expiry.
        // The provider may have received SMS even after our local timer expired.
        if ($order->status === 'pending' && $order->provider_slug === '5sim' && $order->provider_order_id) {
            $providerInfo = $this->poll5SimForSms($order);
            $order->refresh(); // Reload — poll may have updated status/otp_code
        }

        // For non-pending 5sim orders, still fetch provider info for display
        if (!$providerInfo && $order->provider_slug === '5sim' && $order->provider_order_id) {
            $providerInfo = $this->fetch5SimProviderInfo($order);
        }

        // Auto-expire if still pending and past due (only after provider poll found nothing)
        if ($order->status === 'pending' && $order->isExpired()) {
            $order->update(['status' => 'expired']);
            $this->releaseNumber($order);
            $this->releaseInternalNumber($order);
            $this->refundOrder($order, $request->user());
        }

        $order->load(['service:id,name,color,icon', 'country:id,name,flag,dial_code']);

        // Append provider info to the response
        $response = $order->toArray();
        $response['provider_info'] = $providerInfo;

        return response()->json($response);
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

    // --- Helpers ---

    

    /**
     * Poll 5sim API to check if SMS has been received for a pending order.
     * Returns provider info array for the frontend.
     */
    private function poll5SimForSms(NumberOrder $order): ?array
    {
        try {
            $router = new ProviderRouter();
            $fiveSimData = $router->check5SimOrder(
                $order->provider_order_id,
                $order->provider_id
            );

            if (!$fiveSimData) return null;

            $fiveSimStatus = $fiveSimData['status'] ?? '';
            $smsArray = $fiveSimData['sms'] ?? [];

            // If SMS was received on 5sim
            if (!empty($smsArray) && ($fiveSimStatus === 'RECEIVED' || $fiveSimStatus === 'FINISHED')) {
                // Get the last SMS (most recent)
                $lastSms = end($smsArray);
                $smsText = $lastSms['text'] ?? '';
                $smsCode = $lastSms['code'] ?? '';
                $smsSender = $lastSms['sender'] ?? '';

                // Use the extracted code if available, otherwise the full text
                $otpCode = $smsCode ?: $smsText;

                $order->update([
                    'otp_code'     => $otpCode,
                    'sms_from'     => $smsSender,
                    'status'       => 'completed',
                    'completed_at' => now(),
                ]);

                \Log::info("5SIM OTP received for order #{$order->id}: code={$otpCode}, sender={$smsSender}");

                // Track success on the provider
                if ($order->provider_id) {
                    $provider = ApiProvider::find($order->provider_id);
                    if ($provider) {
                        $provider->increment('total_successes');
                    }
                }

                // Finish the 5sim order (mark complete on their side)
                try {
                    $provider = $order->provider_id ? ApiProvider::find($order->provider_id) : null;
                    if ($provider) {
                        $fiveSim = FiveSimService::fromProvider($provider);
                        $fiveSim->finishOrder((int) $order->provider_order_id);
                    }
                } catch (\Exception $e) {
                    \Log::warning("5SIM: Failed to finish order on 5sim side: {$e->getMessage()}");
                }
            }

            // If 5sim order timed out on their side
            if ($fiveSimStatus === 'TIMEOUT') {
                $order->update(['status' => 'expired']);
                $this->refundOrder($order, $order->user);
                \Log::info("5SIM order #{$order->id} timed out (status: {$fiveSimStatus})");
            }

            // If 5sim order was cancelled on their side (e.g. admin cancelled from provider dashboard)
            if (in_array($fiveSimStatus, ['CANCELED', 'BANNED'])) {
                $order->update(['status' => 'cancelled']);
                $this->refundOrder($order, $order->user);
                \Log::info("5SIM order #{$order->id} cancelled on 5sim side (status: {$fiveSimStatus})");
            }

            // Build provider info for frontend
            return $this->build5SimProviderInfo($fiveSimData);

        } catch (\Exception $e) {
            \Log::warning("5SIM poll failed for order #{$order->id}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Fetch provider info from 5sim for non-pending orders (completed/expired/cancelled).
     * Wrapped in try-catch so it never breaks the response.
     */
    private function fetch5SimProviderInfo(NumberOrder $order): ?array
    {
        try {
            $router = new ProviderRouter();
            $fiveSimData = $router->check5SimOrder(
                $order->provider_order_id,
                $order->provider_id
            );
            if (!$fiveSimData) return null;
            return $this->build5SimProviderInfo($fiveSimData);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Build a normalized provider_info payload from raw 5sim API data.
     */
    private function build5SimProviderInfo(array $data): array
    {
        $status = $data['status'] ?? 'UNKNOWN';
        $smsArray = $data['sms'] ?? [];
        $smsArray = is_array($smsArray) ? $smsArray : [];

        // Map 5sim statuses to user-friendly labels
        $statusMap = [
            'PENDING'  => ['label' => 'Preparing Number',   'color' => 'amber'],
            'RECEIVED' => ['label' => 'Active — Waiting for SMS', 'color' => 'emerald'],
            'FINISHED' => ['label' => 'Completed',          'color' => 'blue'],
            'CANCELED' => ['label' => 'Cancelled',          'color' => 'red'],
            'BANNED'   => ['label' => 'Number Banned',      'color' => 'red'],
            'TIMEOUT'  => ['label' => 'Expired',            'color' => 'red'],
        ];

        $mapped = $statusMap[$status] ?? ['label' => $status, 'color' => 'gray'];

        return [
            'provider'        => '5SIM',
            'status'          => $status,
            'status_label'    => $mapped['label'],
            'status_color'    => $mapped['color'],
            'phone'           => $data['phone'] ?? null,
            'operator'        => $data['operator'] ?? null,
            'product'         => $data['product'] ?? null,
            'provider_price'  => $data['price'] ?? null,
            'expires_at'      => $data['expires'] ?? null,
            'created_at'      => $data['created_at'] ?? null,
            'country'         => $data['country'] ?? null,
            'sms_count'       => count($smsArray),
            'sms'             => array_map(function ($sms) {
                return [
                    'sender'     => $sms['sender'] ?? null,
                    'text'       => $sms['text'] ?? null,
                    'code'       => $sms['code'] ?? null,
                    'received_at' => $sms['date'] ?? $sms['created_at'] ?? null,
                ];
            }, $smsArray),
        ];
    }

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
                $order->provider_slug,
                $order->provider_order_id
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




    /**
     * Ban a number — reports it as banned by the platform (e.g. WhatsApp banned it).
     * Cancels the order on 5sim side, refunds wallet.
     */
    public function ban(Request $request, NumberOrder $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (!in_array($order->status, ['pending', 'completed'])) {
            return response()->json(['message' => 'Only pending or completed orders can be banned.'], 422);
        }

        // Ban on 5sim side
        if ($order->provider_slug === '5sim' && $order->provider_order_id) {
            try {
                $provider = ApiProvider::find($order->provider_id);
                if ($provider) {
                    $fiveSim = FiveSimService::fromProvider($provider);
                    $fiveSim->banOrder((int) $order->provider_order_id);
                    \Log::info("5SIM: Banned order #{$order->id} (5sim ID: {$order->provider_order_id})");
                }
            } catch (\Exception $e) {
                \Log::warning("5SIM ban failed for order #{$order->id}: " . $e->getMessage());
            }
        }

        // Refund wallet if not already refunded
        if ($order->status !== 'cancelled') {
            $this->refundOrder($order, $request->user());
        }

        $order->update(['status' => 'cancelled']);

        return response()->json([
            'message'        => 'Number reported as banned. Order cancelled and wallet refunded.',
            'wallet_balance' => $request->user()->wallet?->fresh()?->total_balance,
        ]);
    }

    /**
     * Get available operators and their prices for a service+country combo from 5sim.
     */
    public function operators(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'country_id' => 'required|exists:countries,id',
            'operator' => 'nullable|string|max:50',
        ]);

        $service = Service::findOrFail($request->service_id);
        $country = Country::findOrFail($request->country_id);

        $provider = ApiProvider::where('slug', '5sim')->where('is_active', true)->first();
        if (!$provider) {
            return response()->json(['operators' => []], 200);
        }

        try {
            $fiveSim = FiveSimService::fromProvider($provider);
            $fiveSimCountry = FiveSimService::mapCountryCode($country->code);
            $product = FiveSimService::mapServiceToProduct($service->slug ?? $service->name);

            // Get prices for this country — returns {country: {product: {operator: {cost,count,rate}}}}
            $prices = $fiveSim->getPrices($fiveSimCountry);

            $operators = [];
            $productOperators = $prices[$fiveSimCountry][$product] ?? [];
            foreach ($productOperators as $operatorName => $operatorData) {
                $operators[] = [
                    'name'    => $operatorName,
                    'cost'    => round((float) ($operatorData['cost'] ?? 0), 4),
                    'count'   => (int) ($operatorData['count'] ?? 0),
                    'rate'    => round((float) ($operatorData['rate'] ?? 0), 2),
                ];
            }

            // Sort: "any" first, then by cost ascending
            usort($operators, function ($a, $b) {
                if ($a['name'] === 'any') return -1;
                if ($b['name'] === 'any') return 1;
                return $a['cost'] <=> $b['cost'];
            });

            return response()->json(['operators' => $operators]);
        } catch (\Exception $e) {
            \Log::warning("Failed to fetch 5sim operators: " . $e->getMessage());
            return response()->json(['operators' => [['name' => 'any', 'cost' => 0, 'count' => 0, 'rate' => 0]]]);
        }
    }

    /**
     * Fetch the real operator cost from 5sim and convert to NGN with markup.
     */
    private function resolveDynamicPrice(Service $service, Country $country, string $operator = 'any'): float
    {
        $rate = (float) ApiSetting::getValue('usd_to_ngn_rate', 1500);

        try {
            $provider = ApiProvider::where('slug', '5sim')->where('is_active', true)->first();
            if (!$provider) {
                return $this->resolveCountryPriceFallback($country);
            }

            $fiveSim        = FiveSimService::fromProvider($provider);
            $fiveSimCountry = FiveSimService::mapCountryCode($country->code);
            $product        = FiveSimService::mapServiceToProduct($service->slug ?? $service->name);
            $prices         = $fiveSim->getPrices($fiveSimCountry);

            $productOperators = $prices[$fiveSimCountry][$product] ?? [];

            if (empty($productOperators)) {
                return $this->resolveCountryPriceFallback($country);
            }

            // Find the requested operator's cost
            $costUsd = null;
            if ($operator !== 'any' && isset($productOperators[$operator])) {
                $costUsd = (float) ($productOperators[$operator]['cost'] ?? 0);
            }

            // Fallback: use 'any' operator or pick the cheapest with count > 0
            if (!$costUsd || $costUsd <= 0) {
                if (isset($productOperators['any']) && ($productOperators['any']['count'] ?? 0) > 0) {
                    $costUsd = (float) ($productOperators['any']['cost'] ?? 0);
                }
                if (!$costUsd || $costUsd <= 0) {
                    foreach ($productOperators as $opData) {
                        $opCost = (float) ($opData['cost'] ?? 0);
                        if ($opCost > 0 && ($opData['count'] ?? 0) > 0) {
                            if ($costUsd === null || $opCost < $costUsd) {
                                $costUsd = $opCost;
                            }
                        }
                    }
                }
            }

            if (!$costUsd || $costUsd <= 0) {
                return $this->resolveCountryPriceFallback($country);
            }

            // Use per-provider markup, fall back to global setting
            $markup = (float) ($provider->markup_percent ?? 0);
            if ($markup <= 0) {
                $markup = (float) ApiSetting::getValue('pricing_markup_percent', 0);
            }

            $baseNgn  = round($costUsd * $rate, 2);
            $totalNgn = round($baseNgn * (1 + ($markup / 100)), 2);

            return $totalNgn;

        } catch (\Exception $e) {
            \Log::warning("Dynamic pricing failed: " . $e->getMessage());
            return $this->resolveCountryPriceFallback($country);
        }
    }

    /**
     * Fallback: use static country price if 5sim API is unavailable.
     */
    private function resolveCountryPriceFallback(Country $country): float
    {
        $rate   = (float) ApiSetting::getValue('usd_to_ngn_rate', 1500);
        $markup = (float) ApiSetting::getValue('pricing_markup_percent', 0);

        if ($country->price && (float) $country->price > 0) {
            $base = (float) $country->price;
            return round($base * (1 + ($markup / 100)), 2);
        }
        if ($country->price_usd && (float) $country->price_usd > 0) {
            $base = round((float) $country->price_usd * $rate, 2);
            return round($base * (1 + ($markup / 100)), 2);
        }
        return 0.0;
    }
}
