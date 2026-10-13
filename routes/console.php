<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('makerly:flags-expire')->hourly();
Schedule::command('makerly:reconcile')->dailyAt('02:10');
Schedule::command('makerly:purge-tenants')->dailyAt('03:00');
Schedule::command('makerly:low-stock')->dailyAt('07:00');

Schedule::command('makerly:production-maintain')->hourly()->withoutOverlapping();
