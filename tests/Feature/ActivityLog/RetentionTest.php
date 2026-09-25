<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('schedules the activity log cleanup daily', function () {
    $cleanup = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'activitylog:clean'));

    expect($cleanup)->toHaveCount(1)
        ->and($cleanup->first()->expression)->toBe('0 0 * * *');
});

it('removes activity older than the configured number of days', function () {
    config(['activitylog.clean_after_days' => 30]);
    $this->travelTo('2026-09-25 12:00:00');
    $old = activity()->createdAt(now()->subDays(31))->log('lama');
    $recent = activity()->createdAt(now()->subDays(29))->log('baru');

    $this->artisan('activitylog:clean', ['--force' => true])->assertSuccessful();

    $this->assertModelMissing($old);
    $this->assertModelExists($recent);
});
