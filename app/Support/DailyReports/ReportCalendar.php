<?php

namespace App\Support\DailyReports;

use App\Support\DisplayDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * When daily reports are due (FLOW.md §7): working days, holidays, the
 * cutoff, the back-dating window, and the edit window, all in the display
 * timezone and read from config work_order.daily_reports. Every working-day
 * decision goes through here, so an admin-managed holiday list later only
 * changes holidays(). Dates are calendar dates (Y-m-d).
 */
class ReportCalendar
{
    /**
     * How far recentWorkingDays() and earliestReportDate() look for working
     * days before giving up (a configuration without any).
     */
    private const int SEARCH_LIMIT_DAYS = 366;

    public static function today(): string
    {
        return DisplayDate::today();
    }

    /**
     * Whether a report is due on the date: a configured working day that is
     * not a holiday. Reports may still be filed on any other day.
     */
    public static function isWorkingDay(string $date): bool
    {
        return in_array(self::day($date)->dayOfWeekIso, self::workingDays(), true)
            && ! in_array($date, self::holidays(), true);
    }

    /**
     * The moment the date's report is due: its cutoff time in the display timezone.
     */
    public static function cutoffOf(string $date): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', config()->string('work_order.daily_reports.cutoff')) + [1 => '0']);

        return self::day($date)->setTime($hour, $minute);
    }

    /**
     * Whether today's report is due by now: today is a working day and its cutoff has passed.
     */
    public static function isReportDueNow(): bool
    {
        $today = self::today();

        return self::isWorkingDay($today) && CarbonImmutable::now()->greaterThanOrEqualTo(self::cutoffOf($today));
    }

    /**
     * The earliest date a report may be dated today: the configured number
     * of working days back (0: today). Days in between that are not working
     * days may be reported too.
     */
    public static function earliestReportDate(): string
    {
        $remaining = max(0, config()->integer('work_order.daily_reports.backdate_working_days'));
        $date = self::day(self::today());

        for ($step = 0; $remaining > 0 && $step < self::SEARCH_LIMIT_DAYS; $step++) {
            $date = $date->subDay();

            if (self::isWorkingDay($date->toDateString())) {
                $remaining--;
            }
        }

        return $date->toDateString();
    }

    /**
     * The last $count working days up to today, oldest first.
     *
     * @return list<string>
     */
    public static function recentWorkingDays(int $count): array
    {
        $days = [];
        $date = self::day(self::today());

        for ($step = 0; count($days) < $count && $step < self::SEARCH_LIMIT_DAYS; $step++) {
            if (self::isWorkingDay($date->toDateString())) {
                $days[] = $date->toDateString();
            }

            $date = $date->subDay();
        }

        return array_reverse($days);
    }

    /**
     * The last moment a report created at $createdAt may be edited: the end
     * of that day in the display timezone, plus the configured extra days.
     */
    public static function editableUntil(CarbonInterface $createdAt): CarbonImmutable
    {
        return DisplayDate::local($createdAt)
            ->addDays(max(0, config()->integer('work_order.daily_reports.edit_extra_days')))
            ->endOfDay();
    }

    /**
     * The date of a moment in the display timezone.
     */
    public static function dateOf(CarbonInterface $moment): string
    {
        return DisplayDate::local($moment)->toDateString();
    }

    /**
     * Midnight of the date in the display timezone.
     */
    private static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, DisplayDate::timezone())
            ?: throw new InvalidArgumentException("Invalid date [{$date}].");
    }

    /**
     * @return list<int>
     */
    private static function workingDays(): array
    {
        /** @var list<int> */
        return config()->array('work_order.daily_reports.working_days');
    }

    /**
     * @return list<string>
     */
    private static function holidays(): array
    {
        /** @var list<string> */
        return config()->array('work_order.daily_reports.holidays');
    }
}
