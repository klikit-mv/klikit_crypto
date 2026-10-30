<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('market:sync')->everyMinute()->withoutOverlapping();
Schedule::command('trading:sync-orders')->everyMinute()->withoutOverlapping();
Schedule::command('subscriptions:expire')->hourly();
Schedule::command('ledger:verify')->dailyAt('02:15');
Schedule::command('alerts:check')->everyMinute()->withoutOverlapping();
Schedule::command('signals:expire')->everyMinute();
