<?php

use App\Http\Controllers\Admin\ProviderFetchController;
use App\Http\Controllers\OrderController;
use App\Models\ApiProvider;
use App\Models\ApiSetting;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('usdToNgn handles global and provider-specific markups correctly', function () {
    // 1. Setup global settings: NGN Rate = 1500, Global Markup = 20%
    ApiSetting::setValue('usd_to_ngn_rate', 1500);
    ApiSetting::setValue('pricing_markup_percent', 20);

    $controller = new ProviderFetchController();
    $reflectionUsdToNgn = new \ReflectionMethod(ProviderFetchController::class, 'usdToNgn');
    $reflectionUsdToNgn->setAccessible(true);

    // 2. Test global markup fallback: $1.00 USD should be ₦1800 (1500 * 1.20)
    $priceNgnGlobal = $reflectionUsdToNgn->invoke($controller, 1.00);
    expect($priceNgnGlobal)->toEqual(1800.00);

    // 3. Setup provider with 50% markup
    $provider = ApiProvider::create([
        'name' => 'Test Twilio',
        'slug' => 'twilio-test',
        'type' => 'twilio',
        'credentials' => ['account_sid' => 'xx', 'auth_token' => 'yy'],
        'markup_percent' => 50,
        'priority' => 1,
    ]);

    // 4. Test provider-specific markup: $1.00 USD should be ₦2250 (1500 * 1.50)
    $priceNgnProvider = $reflectionUsdToNgn->invoke($controller, 1.00, $provider);
    expect($priceNgnProvider)->toEqual(2250.00);

    // 5. Test provider with 0% markup falls back to global markup (20%)
    $providerZero = ApiProvider::create([
        'name' => 'Test 5Sim',
        'slug' => 'fivesim-test',
        'type' => '5sim',
        'credentials' => ['api_key' => 'zz'],
        'markup_percent' => 0,
        'priority' => 2,
    ]);

    $priceNgnProviderZero = $reflectionUsdToNgn->invoke($controller, 1.00, $providerZero);
    expect($priceNgnProviderZero)->toEqual(1800.00);
});

test('resolveCountryPriceFallback returns final country price directly without double markup', function () {
    // 1. Setup global settings: NGN Rate = 1500, Global Markup = 20%
    ApiSetting::setValue('usd_to_ngn_rate', 1500);
    ApiSetting::setValue('pricing_markup_percent', 20);

    // 2. Create country with price = 1800 NGN (which already has the 20% markup included)
    $country = Country::create([
        'name' => 'United States',
        'code' => 'US',
        'price_usd' => 1.00,
        'price' => 1800.00,
        'is_active' => true,
    ]);

    $orderController = new OrderController();
    $reflectionFallback = new \ReflectionMethod(OrderController::class, 'resolveCountryPriceFallback');
    $reflectionFallback->setAccessible(true);

    // 3. Test fallback: Should return exactly ₦1800, NOT applying markup again to yield ₦2160
    $finalPrice = $reflectionFallback->invoke($orderController, $country);
    expect($finalPrice)->toEqual(1800.00);

    // 4. If price is 0/null, but price_usd is set to 1.00, it should apply exchange rate and markup once to yield ₦1800
    $countryNoPrice = Country::create([
        'name' => 'Canada',
        'code' => 'CA',
        'price_usd' => 1.00,
        'price' => 0.00,
        'is_active' => true,
    ]);

    $finalPriceNoPrice = $reflectionFallback->invoke($orderController, $countryNoPrice);
    expect($finalPriceNoPrice)->toEqual(1800.00);
});
