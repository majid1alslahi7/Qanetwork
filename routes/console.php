<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sales:reconcile')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
Schedule::command('networks:check-health')->everyMinute()->withoutOverlapping(5);
Schedule::command('sales:recover')->everyMinute()->withoutOverlapping(5);
Schedule::command('sales:recover-reviews')->everyMinute()->withoutOverlapping(5);
Schedule::command('delivery:recover')->everyMinute()->withoutOverlapping(5);
