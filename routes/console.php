<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly: discover today's departures across every tenant with System A
// credentials configured, storing each bus's departure time.
Schedule::command('app:discover-departures')
    ->hourly()
    ->withoutOverlapping()
    ->emailOutputOnFailure(config('mail.from.address'));

// Every minute: upload any bus whose ±10-minute window has arrived,
// retrying failures until 30 minutes past its departure.
Schedule::command('app:watch-departures')
    ->everyMinute()
    ->withoutOverlapping()
    ->emailOutputOnFailure(config('mail.from.address'));


Schedule::command('app:sync-all-passenger-lists')
    ->dailyAt('23:50')
    ->withoutOverlapping()
    ->emailOutputOnFailure(config('mail.from.address'));
