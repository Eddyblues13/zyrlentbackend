<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\SupportTicketUserController;
use App\Http\Controllers\NotificationUserController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\ServiceManagerController;
use App\Http\Controllers\Admin\CountryManagerController;
use App\Http\Controllers\Admin\UserManagerController;
use App\Http\Controllers\Admin\OrderManagerController;
use App\Http\Controllers\Admin\WalletManagerController;
use App\Http\Controllers\Admin\ApiSettingsController;
use App\Http\Controllers\Admin\SupportTicketController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\ReferralManagerController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\ProviderFetchController;
use App\Http\Controllers\Admin\NumberInventoryController;
use Illuminate\Support\Facades\Route;

// ─── Public (no auth) ───────────────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-verification', [AuthController::class, 'resendVerificationCode']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email');
Route::get('/reset-password/{token}', [AuthController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
// ─── SMS Webhooks (no auth — providers POST here) ────────
Route::post('/webhook/sms', [WebhookController::class, 'sms']);
Route::post('/webhook/telnyx', [WebhookController::class, 'telnyxSms']);

// ─── Authenticated User Routes ───────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'updatePassword']);

    // Dashboard overview (summary + wallet balance)
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Services & Countries
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/countries', [CountryController::class, 'index']);
    Route::get("/pricing/calculate", [OrderController::class, "calculatePrice"]);
    Route::get("/operators", [OrderController::class, "operators"]);

    // Orders (purchase history)
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{order}/ban', [OrderController::class, 'ban']);

    // Wallet
    Route::get('/wallet/balance', [WalletController::class, 'balance']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    Route::post('/wallet/manual-fund', [WalletController::class, 'manualFund']);
    
    // KoraPay Funding
    Route::post('/wallet/korapay/initialize', [\App\Http\Controllers\KoraPayController::class, 'initialize']);
    Route::post('/wallet/korapay/verify', [\App\Http\Controllers\KoraPayController::class, 'verify']);

    // Referrals
    Route::get('/referrals', [\App\Http\Controllers\ReferralController::class, 'index']);

    // Support Tickets (user-facing)
    Route::get('/support-tickets', [SupportTicketUserController::class, 'index']);
    Route::post('/support-tickets', [SupportTicketUserController::class, 'store']);
    Route::get('/support-tickets/{ticket}', [SupportTicketUserController::class, 'show']);

    // Notifications (user-facing)
    Route::get('/notifications', [NotificationUserController::class, 'index']);
    Route::get("/notifications/count", [NotificationUserController::class, "count"]);
});

// ═══════════════════════════════════════════════════════════════
// ─── ADMIN ROUTES ─────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════

// Admin public (login)
Route::post('/admin/login', [AdminAuthController::class, 'login']);

// Admin authenticated
Route::prefix('admin')->middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::get('/me', [AdminAuthController::class, 'me']);

    // Dashboard
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);

    // Service Management
    Route::get('/services', [ServiceManagerController::class, 'index']);
    Route::post('/services', [ServiceManagerController::class, 'store']);
    Route::put('/services/{service}', [ServiceManagerController::class, 'update']);
    Route::post('/services/{service}/toggle', [ServiceManagerController::class, 'toggleActive']);
    Route::delete('/services/{service}', [ServiceManagerController::class, 'destroy']);
    Route::get('/services/suggestions', [ServiceManagerController::class, 'fetchSuggestions']);
    Route::post('/services/import', [ServiceManagerController::class, 'importSuggestions']);
    Route::post("/services/bulk-adjust-prices", [ServiceManagerController::class, "bulkAdjustPrices"]);

    // Country Management
    Route::get('/countries', [CountryManagerController::class, 'index']);
    // Countries are now added via API providers only (POST /admin/provider/import-countries)
    Route::put('/countries/{country}', [CountryManagerController::class, 'update']);
    Route::post('/countries/{country}/toggle', [CountryManagerController::class, 'toggleActive']);
    Route::delete('/countries/{country}', [CountryManagerController::class, 'destroy']);
    Route::post("/countries/bulk-adjust-prices", [CountryManagerController::class, "bulkAdjustPrices"]);
    Route::get("/countries/suggestions", [CountryManagerController::class, "fetchSuggestions"]);
    Route::post("/countries/import", [CountryManagerController::class, "import"]);
    
    

    // User Management
    Route::get('/users', [UserManagerController::class, 'index']);
    Route::get('/users-stats', [UserManagerController::class, 'stats']);
    Route::get('/users/{user}', [UserManagerController::class, 'show']);
    Route::post('/users/{user}/credit', [UserManagerController::class, 'creditWallet']);
    Route::post('/users/{user}/debit', [UserManagerController::class, 'debitWallet']);
    Route::post('/users/{user}/suspend', [UserManagerController::class, 'toggleSuspend']);
    Route::post('/users/{user}/email', [UserManagerController::class, 'sendEmail']);
    Route::post('/users/{user}/notify', [UserManagerController::class, 'sendNotification']);
    Route::post('/users/{user}/login-as', [UserManagerController::class, 'loginAsUser']);
    Route::post('/users/{user}/reset-password', [UserManagerController::class, 'resetPassword']);
    Route::get('/users/{user}/login-history', [UserManagerController::class, 'loginHistory']);
    Route::get('/users/{user}/ip-logs', [UserManagerController::class, 'ipLogs']);
    Route::get('/users/{user}/activation-history', [UserManagerController::class, 'activationHistory']);
    Route::post('/users/{user}/toggle-reseller', [UserManagerController::class, 'toggleReseller']);

    // Order Management
    Route::get('/orders', [OrderManagerController::class, 'index']);
    Route::get('/orders/{order}', [OrderManagerController::class, 'show']);
    Route::get('/orders-stats', [OrderManagerController::class, 'stats']);
    Route::post('/orders/{order}/cancel', [OrderManagerController::class, 'cancel']);
    Route::post('/orders/{order}/force-complete', [OrderManagerController::class, 'forceComplete']);
    Route::post('/orders/{order}/refund', [OrderManagerController::class, 'refund']);
    Route::post('/orders/{order}/resend-sms', [OrderManagerController::class, 'resendSms']);

    // Fund Requests (wallet management)
    Route::get('/funds/pending', [WalletManagerController::class, 'pendingFunds']);
    Route::post('/funds/{transaction}/confirm', [WalletManagerController::class, 'confirmFund']);
    Route::post('/funds/{transaction}/reject', [WalletManagerController::class, 'rejectFund']);

    // Support Tickets (admin)
    Route::get('/support-tickets', [SupportTicketController::class, 'index']);
    Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show']);
    Route::post('/support-tickets/{ticket}/reply', [SupportTicketController::class, 'reply']);
    Route::put('/support-tickets/{ticket}/status', [SupportTicketController::class, 'updateStatus']);

    // Notifications (admin)
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/broadcast', [NotificationController::class, 'broadcast']);
    Route::post('/notifications/email-blast', [NotificationController::class, 'emailBlast']);
    Route::put("/notifications/{notification}", [NotificationController::class, "update"]);
    Route::delete("/notifications/{notification}", [NotificationController::class, "destroy"]);
    Route::post("/notifications/{notification}/toggle-active", [NotificationController::class, "toggleActive"]);

    // Referral Management
    Route::get('/referrals', [ReferralManagerController::class, 'index']);
    Route::get('/referrals/stats', [ReferralManagerController::class, 'stats']);

    // API Settings & Pricing
    Route::get('/settings', [ApiSettingsController::class, 'index']);
    Route::put('/settings', [ApiSettingsController::class, 'update']);
    Route::get("/settings/pricing-config", [ApiSettingsController::class, "pricingConfig"]);
    Route::put("/settings/pricing", [ApiSettingsController::class, "updatePricing"]);

    // Provider Management (multi-provider CRUD)
    Route::get("/providers", [ApiSettingsController::class, "providers"]);
    Route::get("/providers/types", [ApiSettingsController::class, "availableTypes"]);
    Route::get("/providers/fields/{type}", [ApiSettingsController::class, "providerFields"]);
    Route::post("/providers", [ApiSettingsController::class, "storeProvider"]);
    Route::put("/providers/{provider}", [ApiSettingsController::class, "updateProvider"]);
    Route::delete("/providers/{provider}", [ApiSettingsController::class, "destroyProvider"]);
    Route::post("/providers/{provider}/toggle", [ApiSettingsController::class, "toggleProvider"]);
    Route::post("/providers/{provider}/reset-metrics", [ApiSettingsController::class, "resetProviderMetrics"]);

    // Routing Config (smart routing)
    Route::get("/routing", [ApiSettingsController::class, "routingConfig"]);
    Route::put("/routing", [ApiSettingsController::class, "updateRouting"]);
    Route::put("/routing/priorities", [ApiSettingsController::class, "updatePriorities"]);

    // Admin Profile
    Route::get('/profile', [AdminProfileController::class, 'show']);
    Route::put('/profile', [AdminProfileController::class, 'update']);
    Route::put('/profile/password', [AdminProfileController::class, 'updatePassword']);


    // Provider Fetch (real-time API)
    Route::get("/provider/list", [ProviderFetchController::class, "providers"]);
    Route::post("/provider/fetch-countries", [ProviderFetchController::class, "fetchCountries"]);
    Route::post("/provider/fetch-numbers", [ProviderFetchController::class, "fetchNumbers"]);
    Route::post("/provider/fetch-pricing", [ProviderFetchController::class, "fetchPricing"]);
    Route::post("/provider/import-countries", [ProviderFetchController::class, "importCountries"]);
    Route::post("/provider/import-numbers", [ProviderFetchController::class, "importNumbers"]);
    Route::post("/provider/fetch-services", [ProviderFetchController::class, "fetchServices"]);
    Route::post("/provider/import-services", [ProviderFetchController::class, "importServices"]);
    // Number Inventory Management
    Route::get("/numbers", [NumberInventoryController::class, "index"]);
    Route::get("/numbers/stats", [NumberInventoryController::class, "stats"]);
    Route::get("/numbers/filter-options", [NumberInventoryController::class, "filterOptions"]);
    // Numbers are now added via API providers only (POST /admin/provider/import-numbers)
    
    Route::put("/numbers/{phoneNumber}", [NumberInventoryController::class, "update"]);
    Route::delete("/numbers/{phoneNumber}", [NumberInventoryController::class, "destroy"]);
    Route::post("/numbers/bulk-delete", [NumberInventoryController::class, "bulkDestroy"]);
    Route::post("/numbers/bulk-status", [NumberInventoryController::class, "bulkUpdateStatus"]);
    Route::post("/numbers/bulk-assign-services", [NumberInventoryController::class, "bulkAssignServices"]);
    Route::post("/numbers/bulk-set-price", [NumberInventoryController::class, "bulkSetPrice"]);
});
