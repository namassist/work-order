<?php

use App\Support\DailyReports\ReportCalendar;
use Carbon\CarbonImmutable;

/*
| Working days, holidays, the cutoff, and the back-dating window of daily
| reports (FLOW.md §7), all in the display timezone (WITA, UTC+8).
| 2026-09-28 is a Monday.
*/

it('treats Monday to Friday as working days by default', function (string $date, bool $working) {
    expect(ReportCalendar::isWorkingDay($date))->toBe($working);
})->with([
    'Monday' => ['2026-09-28', true],
    'Friday' => ['2026-10-02', true],
    'Saturday' => ['2026-10-03', false],
    'Sunday' => ['2026-10-04', false],
]);

it('follows the configured working days and holidays', function () {
    config([
        'work_order.daily_reports.working_days' => [1, 2, 3, 4, 5, 6],
        'work_order.daily_reports.holidays' => ['2026-09-30'],
    ]);

    expect(ReportCalendar::isWorkingDay('2026-10-03'))->toBeTrue()
        ->and(ReportCalendar::isWorkingDay('2026-09-30'))->toBeFalse()
        ->and(ReportCalendar::isWorkingDay('2026-10-04'))->toBeFalse();
});

it('takes today in WITA, so 23:30 UTC is already the next day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 23:30', 'UTC'));

    expect(ReportCalendar::today())->toBe('2026-09-29');
});

it('makes a report due only after the cutoff on a working day', function (string $utc, bool $due) {
    $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

    expect(ReportCalendar::isReportDueNow())->toBe($due);
})->with([
    'Monday 16:59 WITA' => ['2026-09-28 08:59', false],
    'Monday 17:00 WITA' => ['2026-09-28 09:00', true],
    'Monday 23:30 WITA' => ['2026-09-28 15:30', true],
    'Saturday 18:00 WITA' => ['2026-10-03 10:00', false],
]);

it('reads the cutoff from config', function () {
    config(['work_order.daily_reports.cutoff' => '12:00']);
    $this->travelTo(CarbonImmutable::parse('2026-09-28 04:00', 'UTC'));

    expect(ReportCalendar::isReportDueNow())->toBeTrue()
        ->and(ReportCalendar::cutoffOf('2026-09-28')->utc()->format('Y-m-d H:i'))->toBe('2026-09-28 04:00');
});

it('never makes a report due on a holiday', function () {
    config(['work_order.daily_reports.holidays' => ['2026-09-28']]);
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'UTC'));

    expect(ReportCalendar::isReportDueNow())->toBeFalse();
});

it('allows back-dating by working days, skipping weekends and holidays', function (int $days, string $earliest) {
    config([
        'work_order.daily_reports.backdate_working_days' => $days,
        'work_order.daily_reports.holidays' => ['2026-09-24'],
    ]);
    // Monday 28 September, 10:00 WITA.
    $this->travelTo(CarbonImmutable::parse('2026-09-28 02:00', 'UTC'));

    expect(ReportCalendar::earliestReportDate())->toBe($earliest);
})->with([
    'today only' => [0, '2026-09-28'],
    'one: Friday' => [1, '2026-09-25'],
    'two: Thursday is a holiday, so Wednesday' => [2, '2026-09-23'],
]);

it('lists the most recent working days, oldest first, ending today', function () {
    config(['work_order.daily_reports.holidays' => ['2026-09-25']]);
    $this->travelTo(CarbonImmutable::parse('2026-09-29 02:00', 'UTC'));

    expect(ReportCalendar::recentWorkingDays(4))->toBe(['2026-09-23', '2026-09-24', '2026-09-28', '2026-09-29']);
});

it('ends the recent working days before today when today is not one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 02:00', 'UTC'));

    expect(ReportCalendar::recentWorkingDays(2))->toBe(['2026-10-01', '2026-10-02']);
});

it('keeps a report editable until the end of the WITA day it was created, plus the extra days', function (int $extraDays, string $until) {
    config(['work_order.daily_reports.edit_extra_days' => $extraDays]);

    // 23:30 UTC on the 28th is 07:30 WITA on the 29th.
    $editableUntil = ReportCalendar::editableUntil(CarbonImmutable::parse('2026-09-28 23:30', 'UTC'));

    expect($editableUntil->utc()->format('Y-m-d H:i:s'))->toBe($until);
})->with([
    'same day' => [0, '2026-09-29 15:59:59'],
    'one more day' => [1, '2026-09-30 15:59:59'],
]);
