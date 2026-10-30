<?php

use App\Modules\Administration\Http\Controllers\AdminDashboardController;
use App\Modules\Administration\Http\Controllers\PaymentController;
use App\Modules\Administration\Http\Controllers\PlanController;
use App\Modules\Administration\Http\Controllers\SystemController;
use App\Modules\Administration\Http\Controllers\TradingAdminController;
use App\Modules\Administration\Http\Controllers\UserController;
use App\Modules\Administration\Http\Controllers\WalletReviewController;
use Illuminate\Support\Facades\Route;

/*
| Admin area: prefix /admin, name admin.*, middleware web + auth + active + can:admin.access
| (see bootstrap/app.php). Each action additionally checks its own permission.
*/

Route::get('/', AdminDashboardController::class)->name('dashboard');

Route::get('users', [UserController::class, 'index'])->name('users.index');
Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
Route::put('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');
Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])->name('users.roles');

Route::resource('plans', PlanController::class)->except(['show', 'destroy']);

Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
Route::post('payments/{payment}/confirm', [PaymentController::class, 'confirm'])->name('payments.confirm');
Route::post('payments/{payment}/fail', [PaymentController::class, 'fail'])->name('payments.fail');

Route::get('wallet', [WalletReviewController::class, 'index'])->name('wallet.index');
Route::post('deposits/{deposit}/review', [WalletReviewController::class, 'reviewDeposit'])->name('deposits.review');
Route::post('withdrawals/{withdrawal}/review', [WalletReviewController::class, 'reviewWithdrawal'])->name('withdrawals.review');

Route::get('integrations', [TradingAdminController::class, 'integrations'])->name('integrations');
Route::put('integrations/{type}/{id}', [TradingAdminController::class, 'updateIntegration'])->whereIn('type', ['exchange', 'broker'])->name('integrations.update');
Route::get('trading', [TradingAdminController::class, 'controls'])->name('trading.controls');
Route::put('trading/limits', [TradingAdminController::class, 'updateLimits'])->name('trading.limits');
Route::post('trading/halt', [TradingAdminController::class, 'toggleHalt'])->name('trading.halt');
Route::get('trading/orders', [TradingAdminController::class, 'orders'])->name('trading.orders');

Route::get('audit-logs', [SystemController::class, 'auditLogs'])->name('audit');
Route::get('failed-jobs', [SystemController::class, 'failedJobs'])->name('jobs');
Route::get('announcements', [SystemController::class, 'announcements'])->name('announcements.index');
Route::post('announcements', [SystemController::class, 'storeAnnouncement'])->name('announcements.store');
Route::delete('announcements/{announcement}', [SystemController::class, 'destroyAnnouncement'])->name('announcements.destroy');
