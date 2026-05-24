<?php

use App\Http\Controllers\Admin\ProviderFetchController;
use App\Http\Controllers\OrderController;
use App\Models\ApiProvider;
use App\Models\ApiSetting;
use App\Models\Country;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('usdToBaseNgn in ProviderFetchController converts usd to ngn without markup', function () {
    // Setup global settings: NGN Rate = 1500, Global Markup = 50%
    ApiSetting::setValue('usd_to_ngn_rate', 1500);
    ApiSetting::setValue('pricing_markup_percent', 50);

    $controller = new ProviderFetchController();
    $reflection = new \ReflectionMethod(ProviderFetchController::class, 'usdToBaseNgn');
    $reflection->setAccessible(true);

    // $1.00 USD should be exactly ₦1500 (without any markup)
    $priceNgn = $reflection->invoke($controller, 1.00);
    expect($priceNgn)->toEqual(1500.00);

    // $2.50 USD should be exactly ₦3750
    $priceNgn2 = $reflection->invoke($controller, 2.50);
    expect($priceNgn2)->toEqual(3750.00);
});

test('resolveCountryPriceFallback combines country cost and service cost before applying markup', function () {
    // 1. Setup global settings: NGN Rate = 1500, Global Markup = 50%
    ApiSetting::setValue('usd_to_ngn_rate', 1500);
    ApiSetting::setValue('pricing_markup_percent', 50);

    // 2. Create country and service
    // Country cost = 1500 NGN (base price)
    $country = Country::create([
        'name' => 'United States',
        'code' => 'US',
        'price_usd' => 1.00,
        'price' => 1500.00,
        'is_active' => true,
    ]);

    // Service cost = 2000 NGN (base price)
    $service = Service::create([
        'name' => 'WhatsApp',
        'slug' => 'whatsapp',
        'cost' => 2000.00,
        'is_active' => true,
    ]);

    $orderController = new OrderController();
    $reflectionFallback = new \ReflectionMethod(OrderController::class, 'resolveCountryPriceFallback');
    $reflectionFallback->setAccessible(true);

    // 3. Test fallback with 50% markup:
    // Base Price = 1500 + 2000 = 3500 NGN
    // Final Price = 3500 * (1 + 0.50) = 5250 NGN
    $finalPrice = $reflectionFallback->invoke($orderController, $service, $country);
    expect($finalPrice)->toEqual(5250.00);

    // 4. Test fallback with 20% markup:
    ApiSetting::setValue('pricing_markup_percent', 20);
    // Base Price = 1500 + 2000 = 3500 NGN
    // Final Price = 3500 * (1 + 0.20) = 4200 NGN
    $finalPrice2 = $reflectionFallback->invoke($orderController, $service, $country);
    expect($finalPrice2)->toEqual(4200.00);

    // 5. Test fallback when country has no static price but has price_usd
    $countryNoPrice = Country::create([
        'name' => 'Canada',
        'code' => 'CA',
        'price_usd' => 1.00,
        'price' => 0.00,
        'is_active' => true,
    ]);
    // Base Country = 1.00 USD * 1500 = 1500 NGN
    // Base Service = 2000 NGN
    // Base Price = 1500 + 2000 = 3500 NGN
    // Final Price with 20% markup = 3500 * 1.20 = 4200 NGN
    $finalPrice3 = $reflectionFallback->invoke($orderController, $service, $countryNoPrice);
    expect($finalPrice3)->toEqual(4200.00);
});
