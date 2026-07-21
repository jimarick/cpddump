<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cpd:generate-recurring')->dailyAt('06:00')->timezone('Europe/London');
Schedule::command('cpd:prune-evidence')->weeklyOn(0, '05:00')->timezone('Europe/London');
Schedule::command('cpd:send-weekly-reviews')->weeklyOn(1, '07:00')->timezone('Europe/London');
Schedule::command('cpd:send-push-nudges')->weeklyOn(1, '18:00')->timezone('Europe/London');
Schedule::command('cpd:sync-calendars')->weeklyOn(0, '18:00')->timezone('Europe/London');
Schedule::command('cpd:send-morning-gems')->dailyAt('08:00')->timezone('Europe/London');
Schedule::command('cpd:send-monthly-learning-digests')->monthlyOn(1, '08:00')->timezone('Europe/London');
