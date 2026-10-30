<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\TradingBot;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(PlatformSettings $settings): View
    {
        return view('admin.dashboard', [
            'stats' => [
                'users' => User::count(),
                'active_subscriptions' => Subscription::currentlyActive()->count(),
                'revenue_30d' => Payment::where('status', 'completed')->where('completed_at', '>=', now()->subDays(30))->sum('amount'),
                'orders_24h' => Order::where('created_at', '>=', now()->subDay())->count(),
                'failed_orders_24h' => Order::where('status', Order::FAILED)->where('created_at', '>=', now()->subDay())->count(),
                'running_bots' => TradingBot::where('status', 'running')->count(),
                'pending_deposits' => Deposit::where('status', 'pending')->count(),
                'pending_withdrawals' => Withdrawal::where('status', 'pending')->count(),
                'pending_payments' => Payment::where('status', 'pending')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ],
            'tradingHalted' => $settings->tradingHalted(),
            'recentUsers' => User::latest()->limit(8)->get(),
        ]);
    }
}
