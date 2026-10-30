<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('market:sync')->everyMinute()->withoutOverlapping();
Schedule::command('trading:sync-orders')->everyMinute()->withoutOverlapping();
Schedule::command('subscriptions:expire')->hourly();
Schedule::command('ledger:verify')->dailyAt('02:15');
Schedule::command('alerts:check')->everyMinute()->withoutOverlapping();
Schedule::command('signals:expire')->everyMinute();
Schedule::command('bots:run')->everyMinute()->withoutOverlapping();
Schedule::command('bots:performance')->hourly();
Schedule::command('mining:sync-pools')->hourly()->withoutOverlapping();
Schedule::command('mining:profitability')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('subscriptions:renew')->dailyAt('06:10');
Schedule::command('accounts:verify')->dailyAt('03:20');
