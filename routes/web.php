<?php

use App\Modules\Auth\Http\Controllers\Web\SessionController;
use App\Modules\ChartAnalysis\Http\Controllers\ChartController;
use App\Modules\Dashboard\Http\Controllers\Web\DashboardController;
use App\Modules\Mining\Http\Controllers\MiningController;
use App\Modules\Signals\Http\Controllers\SignalController;
use App\Modules\Signals\Http\Controllers\TelegramWebhookController;
use App\Modules\Subscriptions\Http\Controllers\Web\SubscriptionController;
use App\Modules\Trading\Http\Controllers\Web\TradingController;
use App\Modules\TradingBots\Http\Controllers\BotController;
use App\Modules\Wallet\Http\Controllers\Web\WalletController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('login', [SessionController::class, 'showLogin'])->name('login');
    Route::post('login', [SessionController::class, 'login'])->middleware('throttle:auth');
    Route::get('register', [SessionController::class, 'showRegister'])->name('register');
    Route::post('register', [SessionController::class, 'register'])->middleware('throttle:auth');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('logout', [SessionController::class, 'logout'])->name('logout');
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('trading', [TradingController::class, 'index'])->name('trading.index');
    Route::post('trading/orders', [TradingController::class, 'store'])->middleware('throttle:trading')->name('trading.orders.store');
    Route::post('trading/orders/{order}/cancel', [TradingController::class, 'cancel'])->name('trading.orders.cancel');
    Route::post('trading/positions/{position}/close', [TradingController::class, 'closePosition'])->name('trading.positions.close');
    Route::get('trading/risk', [TradingController::class, 'risk'])->name('trading.risk');
    Route::put('trading/risk', [TradingController::class, 'updateRisk'])->name('trading.risk.update');

    Route::get('wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::post('wallet/paper/reset', [WalletController::class, 'resetPaper'])->name('wallet.paper.reset');
    Route::post('wallet/deposits', [WalletController::class, 'deposit'])->name('wallet.deposit');
    Route::post('wallet/withdrawals', [WalletController::class, 'withdraw'])->name('wallet.withdraw');

    Route::get('charts', [ChartController::class, 'page'])->name('charts.index');
    Route::get('charts/data', [ChartController::class, 'data'])->name('charts.data');
    Route::post('charts/alerts', [ChartController::class, 'storeAlert'])->name('charts.alerts.store');
    Route::delete('charts/alerts/{alert}', [ChartController::class, 'destroyAlert'])->name('charts.alerts.destroy');
    Route::post('notifications/read', [ChartController::class, 'markNotificationsRead'])->name('notifications.read');

    Route::get('signals', [SignalController::class, 'page'])->name('signals.index');
    Route::post('signals/channels/{channel}/subscribe', [SignalController::class, 'subscribe'])->name('signals.subscribe');
    Route::delete('signals/channels/{channel}/subscribe', [SignalController::class, 'unsubscribe'])->name('signals.unsubscribe');
    Route::post('signals/executions/{execution}/approve', [SignalController::class, 'approve'])->name('signals.approve');
    Route::post('signals/executions/{execution}/reject', [SignalController::class, 'reject'])->name('signals.reject');

    Route::get('bots', [BotController::class, 'index'])->name('bots.index');
    Route::post('bots', [BotController::class, 'store'])->name('bots.store');
    Route::get('bots/backtests', [BotController::class, 'backtests'])->name('bots.backtests');
    Route::post('bots/backtests', [BotController::class, 'runBacktest'])->name('bots.backtests.run');
    Route::get('bots/{bot}', [BotController::class, 'show'])->name('bots.show');
    Route::put('bots/{bot}', [BotController::class, 'update'])->name('bots.update');
    Route::post('bots/{bot}/start', [BotController::class, 'start'])->name('bots.start');
    Route::post('bots/{bot}/stop', [BotController::class, 'stop'])->name('bots.stop');
    Route::delete('bots/{bot}', [BotController::class, 'destroy'])->name('bots.destroy');
    Route::post('bot-signals/{signal}/approve', [BotController::class, 'approveSignal'])->name('bots.signals.approve');
    Route::post('bot-signals/{signal}/reject', [BotController::class, 'rejectSignal'])->name('bots.signals.reject');

    Route::middleware('feature:mining.management')->prefix('mining')->name('mining.')->group(function () {
        Route::get('/', [MiningController::class, 'index'])->name('index');
        Route::post('devices', [MiningController::class, 'storeDevice'])->name('devices.store');
        Route::get('devices/{device}', [MiningController::class, 'showDevice'])->name('devices.show');
        Route::post('devices/{device}/token', [MiningController::class, 'rotateToken'])->name('devices.token');
        Route::post('devices/{device}/expenses', [MiningController::class, 'storeExpense'])->name('devices.expenses');
        Route::delete('devices/{device}', [MiningController::class, 'destroyDevice'])->name('devices.destroy');
        Route::post('pool-accounts', [MiningController::class, 'storePoolAccount'])->name('pool-accounts.store');
        Route::post('earnings', [MiningController::class, 'storeEarning'])->name('earnings.store');
    });

    Route::get('subscription', [SubscriptionController::class, 'index'])->name('subscription.index');
    Route::post('subscription/checkout', [SubscriptionController::class, 'checkout'])->name('subscription.checkout');
    Route::get('subscription/invoices/{invoice}', [SubscriptionController::class, 'invoice'])->name('subscription.invoice');
    Route::post('subscription/cancel', [SubscriptionController::class, 'cancel'])->name('subscription.cancel');
});

// Telegram Bot API webhook (authenticated by the secret token Telegram echoes back).
Route::post('telegram/webhook/{bot}', [TelegramWebhookController::class, 'telegram'])
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('telegram.webhook');
