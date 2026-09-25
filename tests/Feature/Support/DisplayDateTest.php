<?php

use App\Support\DisplayDate;
use Illuminate\Support\Carbon;

it('formats a UTC moment in the display timezone with Indonesian month names', function () {
    $moment = Carbon::parse('2026-08-02 23:30', 'UTC');

    expect(DisplayDate::dateTime($moment))->toBe('3 Agu 2026 07:30')
        ->and(DisplayDate::date($moment))->toBe('3 Agu 2026');
});

it('follows the configured display timezone', function () {
    config(['app.display_timezone' => 'Asia/Jakarta']);

    expect(DisplayDate::dateTime(Carbon::parse('2026-09-25 03:15', 'UTC')))->toBe('25 Sep 2026 10:15');
});

it('turns a display-timezone day into UTC bounds', function () {
    expect(DisplayDate::startOfDayUtc('2026-09-26')->toDateTimeString())->toBe('2026-09-25 16:00:00')
        ->and(DisplayDate::endOfDayUtc('2026-09-26')->toDateTimeString())->toBe('2026-09-26 15:59:59')
        ->and(DisplayDate::startOfDayUtc('2026-09-26')->timezoneName)->toBe('UTC');
});
