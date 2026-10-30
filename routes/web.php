<?php

use App\Modules\Auth\Http\Controllers\Web\SessionController;
use App\Modules\Dashboard\Http\Controllers\Web\DashboardController;
use App\Modules\Subscriptions\Http\Controllers\Web\SubscriptionController;
use App\Modules\Trading\Http\Controllers\Web\TradingController;
use App\Modules\Wallet\Http\Controllers\Web\WalletController;
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

    Route::get('subscription', [SubscriptionController::class, 'index'])->name('subscription.index');
    Route::post('subscription/checkout', [SubscriptionController::class, 'checkout'])->name('subscription.checkout');
    Route::get('subscription/invoices/{invoice}', [SubscriptionController::class, 'invoice'])->name('subscription.invoice');
    Route::post('subscription/cancel', [SubscriptionController::class, 'cancel'])->name('subscription.cancel');
});
