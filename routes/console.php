<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keeps config('activitylog.clean_after_days') (ACTIVITYLOG_CLEAN_AFTER_DAYS) of audit history.
Schedule::command('activitylog:clean --force')->daily();
