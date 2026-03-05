<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\ServiceManagerController;
use App\Http\Controllers\Admin\CountryManagerController;
use App\Http\Controllers\Admin\UserManagerController;
use App\Http\Controllers\Admin\OrderManagerController;
use App\Http\Controllers\Admin\WalletManagerController;
use App\Http\Controllers\Admin\ApiSettingsController;
use Illuminate\Support\Facades\Route;

// ─── Public (no auth) ───────────────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-verification', [AuthController::class, 'resendVerificationCode']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email');
Route::get('/reset-password/{token}', [AuthController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
// ─── Twilio SMS Webhook (no auth — Twilio posts here) ────────
Route::post('/webhook/sms', [WebhookController::class, 'sms']);

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

    // Orders (purchase history)
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);

    // Wallet
    Route::get('/wallet/balance', [WalletController::class, 'balance']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    Route::post('/wallet/manual-fund', [WalletController::class, 'manualFund']);
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

    // Country Management
    Route::get('/countries', [CountryManagerController::class, 'index']);
    Route::post('/countries', [CountryManagerController::class, 'store']);
    Route::put('/countries/{country}', [CountryManagerController::class, 'update']);
    Route::post('/countries/{country}/toggle', [CountryManagerController::class, 'toggleActive']);
    Route::delete('/countries/{country}', [CountryManagerController::class, 'destroy']);
    Route::get('/countries/suggestions', [CountryManagerController::class, 'fetchSuggestions']);
    Route::post('/countries/import', [CountryManagerController::class, 'import']);

    // User Management
    Route::get('/users', [UserManagerController::class, 'index']);
    Route::get('/users/{user}', [UserManagerController::class, 'show']);
    Route::post('/users/{user}/credit', [UserManagerController::class, 'creditWallet']);

    // Order Management
    Route::get('/orders', [OrderManagerController::class, 'index']);
    Route::get('/orders/{order}', [OrderManagerController::class, 'show']);

    // Fund Requests (wallet management)
    Route::get('/funds/pending', [WalletManagerController::class, 'pendingFunds']);
    Route::post('/funds/{transaction}/confirm', [WalletManagerController::class, 'confirmFund']);
    Route::post('/funds/{transaction}/reject', [WalletManagerController::class, 'rejectFund']);

    // API Settings
    Route::get('/settings', [ApiSettingsController::class, 'index']);
    Route::put('/settings', [ApiSettingsController::class, 'update']);
});
