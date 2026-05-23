<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Service;
use App\Models\Country;
use App\Models\NumberOrder;
use App\Models\ApiProvider;
use App\Models\ApiSetting;
use App\Models\Transaction;
use App\Services\FiveSimService;
use App\Services\SmsPoolService;
use App\Services\ProviderRouter;
use App\Events\OtpReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class DeveloperApiController extends Controller
{
    // ═══════════════════════════════════════════════════════════════
    //  API KEY MANAGEMENT (Authenticated via Sanctum/web)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the user's current API Key.
     */
    public function getApiKey(Request $request)
    {
        return response()->json([
            'api_key' => $request->user()->api_key,
        ]);
    }

    /**
     * Generate or regenerate a new API Key.
     */
    public function generateApiKey(Request $request)
    {
        $user = $request->user();
        
        // Generate key with zyr_ prefix + 40 char secure random string
        $newKey = 'zyr_api_' . Str::random(40);
        
        $user->api_key = $newKey;
        $user->save();

        return response()->json([
            'message' => 'API Key generated successfully.',
            'api_key' => $newKey,
        ]);
    }

    /**
     * Revoke the current API Key.
     */
    public function revokeApiKey(Request $request)
    {
        $user = $request->user();
        
        $user->api_key = null;
        $user->save();

        return response()->json([
            'message' => 'API Key revoked successfully.',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  DEVELOPER ENDPOINTS (Authenticated via DeveloperApiAuth middleware)
    // ═══════════════════════════════════════════════════════════════

    /**
     * 5SIM Compatible: Get user profile & balance
     * GET /api/v1/user/profile
     */
    public function profile(Request $request)
    {
        $user = $request->user();
        $wallet = $user->wallet()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0.00]
        );

        return response()->json([
            'email' => $user->email,
            'balance' => (float) $wallet->total_balance,
            'rating' => 5,
        ]);
    }

    /**
     * 5SIM Compatible: Buy an activation number
     * GET /api/v1/user/buy/activation/{country}/{operator}/{product}
     */
    public function buy(Request $request, $country, $operator, $product)
    {
        $user = $request->user();

        // 1. Resolve Country
        $countryQuery = strtolower(trim($country));
        $countryModel = Country::where('is_active', true)->get()->first(function ($c) use ($countryQuery) {
            $normalizedName = strtolower(str_replace([' ', '-', '_'], '', $c->name));
            $normalizedQuery = str_replace([' ', '-', '_'], '', $countryQuery);
            return $normalizedName === $normalizedQuery || strtolower($c->code) === $countryQuery;
        });

        if (!$countryModel) {
            return response()->json(['message' => 'Country not found or currently inactive.'], 404);
        }

        // 2. Resolve Service
        $productQuery = strtolower(trim($product));
        $serviceModel = Service::where('is_active', true)->get()->first(function ($s) use ($productQuery) {
            return strtolower($s->name) === $productQuery || strtolower($s->slug) === $productQuery;
        });

        if (!$serviceModel) {
            return response()->json(['message' => 'Service not found or currently inactive.'], 404);
        }

        // 3. Resolve Pricing
        $operator = $operator ?: 'any';
        $cost = $this->resolveDynamicPrice($serviceModel, $countryModel, $operator);

        if ($cost <= 0) {
            return response()->json(['message' => 'Pricing not configured for this combination.'], 422);
        }

        // 4. Rate Limiting (max 3 orders per user per minute)
        $recentOrders = NumberOrder::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        if ($recentOrders >= 3) {
            return response()->json(['message' => 'Too many orders. Please wait a minute.'], 429);
        }

        // 5. Wallet check
        $wallet = $user->wallet()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0.00]
        );

        if ($wallet->total_balance < $cost) {
            return response()->json([
                'message' => 'Insufficient wallet balance.',
                'required' => $cost,
                'balance' => $wallet->total_balance,
            ], 402);
        }

        // 6. Provision number via Smart Router
        $router = new ProviderRouter;
        try {
            $allocation = $router->allocateNumber($countryModel, $serviceModel->slug ?? null, $operator);
        } catch (\Exception $e) {
            Log::error('API ProviderRouter allocation failed', [
                'user_id' => $user->id,
                'service_id' => $serviceModel->id,
                'country_id' => $countryModel->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => $e->getMessage()], 502);
        }

        // 7. Deduct wallet & save order in atomic transaction
        try {
            $order = DB::transaction(function () use ($user, $cost, $serviceModel, $countryModel, $allocation, $request) {
                $freshWallet = $user->wallet()->lockForUpdate()->first();
                if ($freshWallet->total_balance < $cost) {
                    throw new \Exception('Insufficient wallet balance.');
                }

                $freshWallet->deduct($cost, "API Number rental: {$serviceModel->name} ({$countryModel->name})", [
                    'service_id' => $serviceModel->id,
                    'country_id' => $countryModel->id,
                    'via_api' => true,
                ]);

                return NumberOrder::create([
                    'user_id' => $user->id,
                    'service_id' => $serviceModel->id,
                    'country_id' => $countryModel->id,
                    'order_ref' => 'ORD-' . strtoupper(Str::random(8)),
                    'phone_number' => $allocation['phone_number'],
                    'twilio_sid' => $allocation['provider_sid'],
                    'status' => 'pending',
                    'cost' => $cost,
                    'expires_at' => now()->addMinutes((int) env('NUMBER_EXPIRY_MINUTES', 20)),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent() ?: 'Zyrlent-API-Client',
                    'provider_id' => $allocation['provider_id'],
                    'provider_slug' => $allocation['provider_slug'],
                    'provider_order_id' => $allocation['provider_order_id'] ?? null,
                    'provider_response_ms' => $allocation['response_ms'],
                    'retry_count' => $allocation['retry_count'],
                    'routing_log' => $allocation['routing_log'],
                ]);
            });
        } catch (\Exception $e) {
            try {
                $router->releaseNumber(
                    $allocation['provider_sid'],
                    $allocation['provider_id'],
                    $allocation['provider_slug'],
                    $allocation['provider_order_id'] ?? null
                );
            } catch (\Exception $releaseEx) {
                Log::error("API Release cleanup failed: " . $releaseEx->getMessage());
            }
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // 8. Instant poll
        if ($order->provider_order_id) {
            if ($order->provider_slug === '5sim') {
                try {
                    $this->poll5SimForSms($order);
                    $order->refresh();
                } catch (\Exception $e) {}
            } elseif ($order->provider_slug === 'smspool') {
                try {
                    $this->pollSmsPoolForSms($order);
                    $order->refresh();
                } catch (\Exception $e) {}
            }
        }

        return response()->json($this->formatOrder5Sim($order));
    }

    /**
     * 5SIM Compatible: Check OTP status
     * GET /api/v1/user/check/{id}
     */
    public function check(Request $request, $id)
    {
        $order = NumberOrder::where('user_id', $request->user()->id)->findOrFail($id);

        if ($order->status === 'pending' && $order->provider_order_id) {
            if ($order->provider_slug === '5sim') {
                $cacheKey = "5sim_poll_{$order->id}";
                $lastPoll = cache()->get($cacheKey);

                if (!$lastPoll || now()->diffInSeconds($lastPoll) >= 5) {
                    cache()->put($cacheKey, now(), 30);
                    try {
                        $this->poll5SimForSms($order);
                        $order->refresh();
                    } catch (\Exception $e) {}
                }
            } elseif ($order->provider_slug === 'smspool') {
                $cacheKey = "smspool_poll_{$order->id}";
                $lastPoll = cache()->get($cacheKey);

                if (!$lastPoll || now()->diffInSeconds($lastPoll) >= 5) {
                    cache()->put($cacheKey, now(), 30);
                    try {
                        $this->pollSmsPoolForSms($order);
                        $order->refresh();
                    } catch (\Exception $e) {}
                }
            }
        }

        // Expire if necessary
        if ($order->status === 'pending' && $order->isExpired()) {
            $order->update(['status' => 'expired']);
            $this->releaseNumber($order);
            $this->releaseInternalNumber($order);
            $this->refundOrder($order, $request->user());
            $order->refresh();
        }

        return response()->json($this->formatOrder5Sim($order));
    }

    /**
     * 5SIM Compatible: Finish order
     * GET /api/v1/user/finish/{id}
     */
    public function finish(Request $request, $id)
    {
        $order = NumberOrder::where('user_id', $request->user()->id)->findOrFail($id);

        // If completed or expired, returns the current status
        return response()->json($this->formatOrder5Sim($order));
    }

    /**
     * 5SIM Compatible: Cancel order
     * GET /api/v1/user/cancel/{id}
     */
    public function cancel(Request $request, $id)
    {
        $order = NumberOrder::where('user_id', $request->user()->id)->findOrFail($id);

        if ($order->status === 'pending') {
            $this->releaseNumber($order);
            $this->releaseInternalNumber($order);
            $this->refundOrder($order, $request->user());
            $order->update(['status' => 'cancelled']);
            $order->refresh();
        }

        return response()->json($this->formatOrder5Sim($order));
    }

    /**
     * 5SIM Compatible: Ban order
     * GET /api/v1/user/ban/{id}
     */
    public function ban(Request $request, $id)
    {
        $order = NumberOrder::where('user_id', $request->user()->id)->findOrFail($id);

        if ($order->status === 'pending' || $order->status === 'completed') {
            // Ban on 5sim side
            if ($order->provider_slug === '5sim' && $order->provider_order_id) {
                try {
                    $provider = ApiProvider::find($order->provider_id);
                    if ($provider) {
                        $fiveSim = FiveSimService::fromProvider($provider);
                        $fiveSim->banOrder((int) $order->provider_order_id);
                    }
                } catch (\Exception $e) {
                    Log::warning("API 5SIM ban failed: " . $e->getMessage());
                }
            }

            // Cancel on SMSPool side
            if ($order->provider_slug === 'smspool' && $order->provider_order_id) {
                try {
                    $provider = ApiProvider::find($order->provider_id);
                    if ($provider) {
                        $smsPool = SmsPoolService::fromProvider($provider);
                        $smsPool->cancelOrder($order->provider_order_id);
                    }
                } catch (\Exception $e) {
                    Log::warning("API SMSPool cancel failed: " . $e->getMessage());
                }
            }

            if ($order->status !== 'cancelled') {
                $this->refundOrder($order, $request->user());
            }

            $order->update(['status' => 'cancelled']);
            $order->refresh();
        }

        return response()->json($this->formatOrder5Sim($order));
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE UTILITIES / HELPERS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Helper to format our model order to 5SIM API specifications
     */
    private function formatOrder5Sim(NumberOrder $order): array
    {
        $statusMap = [
            'pending' => 'RECEIVED',
            'completed' => 'FINISHED',
            'cancelled' => 'CANCELED',
            'expired' => 'TIMEOUT',
        ];

        $sms = null;
        if ($order->status === 'completed' && $order->otp_code) {
            $sms = [
                [
                    'created_at' => $order->completed_at ? $order->completed_at->toIso8601String() : now()->toIso8601String(),
                    'date' => $order->completed_at ? $order->completed_at->toIso8601String() : now()->toIso8601String(),
                    'sender' => $order->sms_from ?: 'SMS',
                    'text' => "Your code is " . $order->otp_code,
                    'code' => $order->otp_code,
                ]
            ];
        }

        return [
            'id' => $order->id,
            'phone' => $order->phone_number,
            'operator' => $order->provider_slug ?: 'any',
            'product' => $order->service ? strtolower($order->service->name) : 'other',
            'price' => (float) $order->cost,
            'status' => $statusMap[$order->status] ?? 'RECEIVED',
            'expires' => $order->expires_at ? $order->expires_at->toIso8601String() : null,
            'sms' => $sms,
            'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
            'country' => $order->country ? strtolower($order->country->name) : 'any',
        ];
    }

    private function poll5SimForSms(NumberOrder $order): ?array
    {
        try {
            $router = new ProviderRouter;
            $fiveSimData = $router->check5SimOrder($order->provider_order_id, $order->provider_id);

            if (!$fiveSimData) return null;

            $fiveSimStatus = $fiveSimData['status'] ?? '';
            $smsArray = $fiveSimData['sms'] ?? [];

            if (!empty($smsArray) && ($fiveSimStatus === 'RECEIVED' || $fiveSimStatus === 'FINISHED')) {
                $lastSms = end($smsArray);
                $smsText = $lastSms['text'] ?? '';
                $smsCode = $lastSms['code'] ?? '';
                $smsSender = $lastSms['sender'] ?? '';
                $otpCode = $smsCode ?: $smsText;

                $order->update([
                    'otp_code' => $otpCode,
                    'sms_from' => $smsSender,
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                try {
                    $order->refresh();
                    OtpReceived::dispatch($order);
                } catch (\Exception $e) {}

                if ($order->provider_id) {
                    $provider = ApiProvider::find($order->provider_id);
                    if ($provider) $provider->increment('total_successes');
                }

                try {
                    $provider = $order->provider_id ? ApiProvider::find($order->provider_id) : null;
                    if ($provider) {
                        $fiveSim = FiveSimService::fromProvider($provider);
                        $fiveSim->finishOrder((int) $order->provider_order_id);
                    }
                } catch (\Exception $e) {}
            }

            if ($fiveSimStatus === 'TIMEOUT') {
                $order->update(['status' => 'expired']);
                $this->refundOrder($order, $order->user);
            }

            if (in_array($fiveSimStatus, ['CANCELED', 'BANNED'])) {
                $order->update(['status' => 'cancelled']);
                $this->refundOrder($order, $order->user);
            }

            return $this->build5SimProviderInfo($fiveSimData);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function pollSmsPoolForSms(NumberOrder $order): ?array
    {
        try {
            $router = new ProviderRouter;
            $smsPoolData = $router->checkSmsPoolOrder($order->provider_order_id, $order->provider_id);

            if (!$smsPoolData) return null;

            $statusCode = (int) ($smsPoolData['status'] ?? 0);
            $statusName = SmsPoolService::mapStatusCode($statusCode);
            $smsCode = $smsPoolData['code'] ?? '';
            $smsText = $smsPoolData['sms'] ?? $smsPoolData['full_sms'] ?? '';

            if ($statusCode === 3 && ($smsCode || $smsText)) {
                $otpCode = $smsCode ?: $smsText;

                $order->update([
                    'otp_code' => $otpCode,
                    'sms_from' => 'SMSPool',
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                try {
                    $order->refresh();
                    OtpReceived::dispatch($order);
                } catch (\Exception $e) {}

                if ($order->provider_id) {
                    $provider = ApiProvider::find($order->provider_id);
                    if ($provider) $provider->increment('total_successes');
                }
            }

            if ($statusCode === 2) {
                $order->update(['status' => 'expired']);
                $this->refundOrder($order, $order->user);
            }

            if (in_array($statusCode, [5, 6])) {
                $order->update(['status' => 'cancelled']);
                $this->refundOrder($order, $order->user);
            }

            return $this->buildSmsPoolProviderInfo($smsPoolData);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function build5SimProviderInfo(array $data): array
    {
        $status = $data['status'] ?? 'UNKNOWN';
        $smsArray = $data['sms'] ?? [];
        $smsArray = is_array($smsArray) ? $smsArray : [];

        return [
            'provider' => '5SIM',
            'status' => $status,
            'phone' => $data['phone'] ?? null,
            'operator' => $data['operator'] ?? null,
            'product' => $data['product'] ?? null,
            'provider_price' => $data['price'] ?? null,
            'sms_count' => count($smsArray),
        ];
    }

    private function buildSmsPoolProviderInfo(array $data): array
    {
        $statusCode = (int) ($data['status'] ?? 0);
        $statusName = SmsPoolService::mapStatusCode($statusCode);

        return [
            'provider' => 'SMSPool',
            'status' => $statusName,
            'phone' => $data['phonenumber'] ?? null,
            'sms_count' => !empty($data['sms'] ?? $data['code'] ?? '') ? 1 : 0,
        ];
    }

    private function releaseNumber(NumberOrder $order): void
    {
        if (!$order->provider_order_id && !$order->twilio_sid) return;

        try {
            $router = new ProviderRouter;
            $router->releaseNumber(
                $order->provider_order_id ?? $order->twilio_sid,
                $order->provider_id,
                $order->provider_slug,
                $order->provider_order_id
            );
        } catch (\Exception $e) {
            Log::warning("API release failed: " . $e->getMessage());
        }
    }

    private function releaseInternalNumber(NumberOrder $order): void
    {
        if ($order->provider_slug !== 'internal') return;

        $phoneNumber = \App\Models\PhoneNumber::where('phone_number', $order->phone_number)
            ->where('status', 'in_use')
            ->first();

        if ($phoneNumber) {
            $phoneNumber->release();
        }
    }

    private function refundOrder(NumberOrder $order, $user): void
    {
        try {
            $alreadyRefunded = Transaction::where('type', 'credit')
                ->where('meta->order_id', $order->id)
                ->exists();
        } catch (\Exception $e) {
            $alreadyRefunded = Transaction::where('type', 'credit')
                ->where('description', 'like', "%Refund: {$order->order_ref}%")
                ->exists();
        }

        if ($alreadyRefunded) return;

        $wallet = $user->wallet;
        if ($wallet && $order->cost > 0) {
            $wallet->credit((float) $order->cost, "Refund: {$order->order_ref}", [
                'order_id' => $order->id,
            ]);
        }
    }

    private function resolveDynamicPrice(Service $service, Country $country, string $operator = 'any'): float
    {
        $rate = (float) ApiSetting::getValue('usd_to_ngn_rate', 1500);

        try {
            $provider = ApiProvider::where('slug', '5sim')->where('is_active', true)->first();
            if (!$provider) {
                return $this->resolveCountryPriceFallback($country);
            }

            $fiveSim = FiveSimService::fromProvider($provider);
            $fiveSimCountry = FiveSimService::mapCountryCode($country->code);
            $product = FiveSimService::mapServiceToProduct($service->slug ?? $service->name);
            $prices = $fiveSim->getPrices($fiveSimCountry);

            $productOperators = $prices[$fiveSimCountry][$product] ?? [];

            if (empty($productOperators)) {
                return $this->resolveCountryPriceFallback($country);
            }

            $costUsd = null;
            if ($operator !== 'any' && isset($productOperators[$operator])) {
                $costUsd = (float) ($productOperators[$operator]['cost'] ?? 0);
            }

            if (!$costUsd || $costUsd <= 0) {
                if ($operator === 'any') {
                    $selectedOp = FiveSimService::selectBestOperator($productOperators);
                    if ($selectedOp !== 'any' && isset($productOperators[$selectedOp])) {
                        $costUsd = (float) ($productOperators[$selectedOp]['cost'] ?? 0);
                    }
                }

                if (!$costUsd || $costUsd <= 0) {
                    if (isset($productOperators['any']) && ($productOperators['any']['count'] ?? 0) > 0) {
                        $costUsd = (float) ($productOperators['any']['cost'] ?? 0);
                    }
                }

                if (!$costUsd || $costUsd <= 0) {
                    foreach ($productOperators as $opData) {
                        $opCost = (float) ($opData['cost'] ?? 0);
                        if ($opCost > 0 && ($opData['count'] ?? 0) > 0) {
                            if ($costUsd === null || $opCost > $costUsd) {
                                $costUsd = $opCost;
                            }
                        }
                    }
                }
            }

            if (!$costUsd || $costUsd <= 0) {
                return $this->resolveCountryPriceFallback($country);
            }

            $markup = (float) ($provider->markup_percent ?? 0);
            if ($markup <= 0) {
                $markup = (float) ApiSetting::getValue('pricing_markup_percent', 0);
            }

            $baseNgn = round($costUsd * $rate, 2);
            $totalNgn = round($baseNgn * (1 + ($markup / 100)), 2);

            return $totalNgn;

        } catch (\Exception $e) {
            return $this->resolveCountryPriceFallback($country);
        }
    }

    private function resolveCountryPriceFallback(Country $country): float
    {
        $rate = (float) ApiSetting::getValue('usd_to_ngn_rate', 1500);
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
