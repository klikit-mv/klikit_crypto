<?php

use App\Modules\Auth\Http\Controllers\Api\AuthController;
use App\Modules\Dashboard\Http\Controllers\Api\DashboardController;
use App\Modules\Subscriptions\Http\Controllers\Api\SubscriptionController;
use App\Modules\Trading\Http\Controllers\Api\MarketController;
use App\Modules\Trading\Http\Controllers\Api\OrderController;
use App\Modules\Trading\Http\Controllers\Api\PositionController;
use App\Modules\Trading\Http\Controllers\Api\RiskProfileController;
use App\Modules\Trading\Http\Controllers\Api\VenueAccountController;
use App\Modules\Wallet\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

/*
| REST API v1 (prefix /api/v1). Authenticate with a Sanctum bearer token.
| Financial and trading writes require an Idempotency-Key header.
*/

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::get('subscriptions/plans', [SubscriptionController::class, 'plans']);

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/profile', [AuthController::class, 'profile']);
    Route::put('auth/profile', [AuthController::class, 'updateProfile']);

    Route::get('dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('dashboard/statistics', [DashboardController::class, 'statistics']);

    // Crypto (SPOT, FUTURES) and Forex (FOREX, CFD) share controllers; {market} selects the instruments.
    Route::prefix('{market}')->whereIn('market', ['crypto', 'forex'])->group(function () {
        Route::get('markets', [MarketController::class, 'symbols']);
        Route::get('symbols', [MarketController::class, 'symbols']);
        Route::get('prices', [MarketController::class, 'prices']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store'])->middleware(['throttle:trading', 'idempotent']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('idempotent');
        Route::get('trades', [OrderController::class, 'trades']);
    });

    Route::get('positions', [PositionController::class, 'index']);
    Route::post('positions/{position}/close', [PositionController::class, 'close'])->middleware('idempotent');

    Route::get('trading/risk-profile', [RiskProfileController::class, 'show']);
    Route::put('trading/risk-profile', [RiskProfileController::class, 'update']);
    Route::post('trading/emergency-stop', [RiskProfileController::class, 'emergencyStop']);

    Route::get('accounts', [VenueAccountController::class, 'index']);
    Route::post('accounts/exchanges', [VenueAccountController::class, 'storeExchange']);
    Route::post('accounts/brokers', [VenueAccountController::class, 'storeBroker']);
    Route::delete('accounts/exchanges/{account}', [VenueAccountController::class, 'destroyExchange']);
    Route::delete('accounts/brokers/{account}', [VenueAccountController::class, 'destroyBroker']);

    Route::prefix('wallet')->group(function () {
        Route::get('balances', [WalletController::class, 'balances']);
        Route::get('transactions', [WalletController::class, 'transactions']);
        Route::post('paper/reset', [WalletController::class, 'resetPaper']);
        Route::get('deposits', [WalletController::class, 'deposits']);
        Route::post('deposits', [WalletController::class, 'requestDeposit'])->middleware('idempotent');
        Route::get('withdrawals', [WalletController::class, 'withdrawals']);
        Route::post('withdrawals', [WalletController::class, 'requestWithdrawal'])->middleware('idempotent');
    });

    Route::prefix('subscriptions')->group(function () {
        Route::get('current', [SubscriptionController::class, 'current']);
        Route::post('checkout', [SubscriptionController::class, 'checkout'])->middleware('idempotent');
        Route::post('cancel', [SubscriptionController::class, 'cancel']);
        Route::get('invoices', [SubscriptionController::class, 'invoices']);
    });
});
