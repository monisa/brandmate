<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Where the host runs `php artisan schedule:run` every minute, reminders go out once a day by themselves.
// Otherwise run `php artisan subscriptions:remind` from a daily cron job: it is safe to repeat.
Schedule::command('subscriptions:remind')->dailyAt('09:00')->withoutOverlapping();
