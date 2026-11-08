<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notifications:scan')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('owners:accrue-dues')
    ->dailyAt('00:30')
    ->withoutOverlapping();
