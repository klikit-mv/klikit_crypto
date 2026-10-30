<?php

use App\Modules\Auth\Http\Controllers\Api\AuthController;
use App\Modules\ChartAnalysis\Http\Controllers\ChartController;
use App\Modules\Dashboard\Http\Controllers\Api\DashboardController;
use App\Modules\Mining\Http\Controllers\MiningAgentController;
use App\Modules\Mining\Http\Controllers\MiningController;
use App\Modules\Signals\Http\Controllers\SignalController;
use App\Modules\Signals\Http\Controllers\TelegramWebhookController;
use App\Modules\Subscriptions\Http\Controllers\Api\SubscriptionController;
use App\Modules\Trading\Http\Controllers\Api\MarketController;
use App\Modules\Trading\Http\Controllers\Api\OrderController;
use App\Modules\Trading\Http\Controllers\Api\PositionController;
use App\Modules\Trading\Http\Controllers\Api\RiskProfileController;
use App\Modules\Trading\Http\Controllers\Api\VenueAccountController;
use App\Modules\TradingBots\Http\Controllers\BotController;
use App\Modules\Wallet\Http\Controllers\Api\WalletController;
use Illuminate\Http\Request;
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
Route::post('mining/agent/stats', [MiningAgentController::class, 'stats'])->middleware('throttle:60,1');
Route::post('signals/ingest/{channel}', [TelegramWebhookController::class, 'ingest'])->middleware('throttle:120,1');

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

    Route::get('charts/{symbol}', [ChartController::class, 'data']);
    Route::get('alerts', [ChartController::class, 'alerts']);
    Route::post('alerts', [ChartController::class, 'storeAlert']);
    Route::delete('alerts/{alert}', [ChartController::class, 'destroyAlert']);
    Route::get('notifications', fn (Request $r) => $r->user()->notifications()->latest()->paginate(25));

    Route::prefix('signals')->group(function () {
        Route::get('/', [SignalController::class, 'list']);
        Route::get('history', [SignalController::class, 'history']);
        Route::get('channels', [SignalController::class, 'channels']);
        Route::post('channels/{channel}/subscribe', [SignalController::class, 'subscribe']);
        Route::delete('channels/{channel}/subscribe', [SignalController::class, 'unsubscribe']);
        Route::post('executions/{execution}/approve', [SignalController::class, 'approve'])->middleware('idempotent');
        Route::post('executions/{execution}/reject', [SignalController::class, 'reject']);
    });

    Route::prefix('bots')->group(function () {
        Route::get('/', [BotController::class, 'index']);
        Route::post('/', [BotController::class, 'store']);
        Route::get('backtests', [BotController::class, 'backtests']);
        Route::post('backtests', [BotController::class, 'runBacktest']);
        Route::get('{bot}', [BotController::class, 'show']);
        Route::get('{bot}/performance', [BotController::class, 'show']);
        Route::put('{bot}', [BotController::class, 'update']);
        Route::post('{bot}/start', [BotController::class, 'start']);
        Route::post('{bot}/stop', [BotController::class, 'stop']);
        Route::delete('{bot}', [BotController::class, 'destroy']);
        Route::post('signals/{signal}/approve', [BotController::class, 'approveSignal'])->middleware('idempotent');
        Route::post('signals/{signal}/reject', [BotController::class, 'rejectSignal']);
    });

    Route::middleware('feature:mining.management')->prefix('mining')->group(function () {
        Route::get('devices', [MiningController::class, 'index']);
        Route::post('devices', [MiningController::class, 'storeDevice']);
        Route::get('devices/{device}', [MiningController::class, 'showDevice']);
        Route::post('devices/{device}/token', [MiningController::class, 'rotateToken']);
        Route::post('devices/{device}/expenses', [MiningController::class, 'storeExpense']);
        Route::delete('devices/{device}', [MiningController::class, 'destroyDevice']);
        Route::get('workers', [MiningController::class, 'statistics']);
        Route::get('statistics', [MiningController::class, 'statistics']);
        Route::get('profitability', [MiningController::class, 'index']);
        Route::post('pool-accounts', [MiningController::class, 'storePoolAccount']);
        Route::post('earnings', [MiningController::class, 'storeEarning']);
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
