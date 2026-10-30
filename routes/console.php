<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Market data and trading
Schedule::command('market:sync')->everyMinute()->withoutOverlapping();
Schedule::command('trading:sync-orders')->everyMinute()->withoutOverlapping();
Schedule::command('alerts:check')->everyMinute()->withoutOverlapping();
Schedule::command('signals:expire')->everyMinute();
Schedule::command('bots:run')->everyMinute()->withoutOverlapping();
Schedule::command('bots:performance')->hourly();
Schedule::command('accounts:verify')->dailyAt('03:20');

// Billing
Schedule::command('subscriptions:expire')->hourly();
Schedule::command('subscriptions:renew')->dailyAt('06:10');

// Mining
Schedule::command('mining:sync-pools')->hourly()->withoutOverlapping();
Schedule::command('mining:profitability')->everyFifteenMinutes()->withoutOverlapping();

// Operations
Schedule::command('ledger:verify')->dailyAt('02:15');
Schedule::command('backup:database')->dailyAt('01:40');
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::call(fn () => Cache::forever(HealthController::SCHEDULER_HEARTBEAT_KEY, now()->getTimestamp()))
    ->everyMinute()->name('scheduler-heartbeat');
