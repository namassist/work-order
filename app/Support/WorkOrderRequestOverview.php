<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\WorkOrderStatus;
use Carbon\CarbonImmutable;

/**
 * The dashboard's "Ringkasan Pengajuan": work orders the user may see,
 * created per calendar day in the display timezone and counted by their
 * current status. Days without work orders are included with zero counts.
 */
class WorkOrderRequestOverview
{
    /**
     * The periods, in days, the dashboard offers.
     *
     * @var list<int>
     */
    public const array PERIODS = [7, 30];

    public const int DEFAULT_PERIOD = 7;

    /**
     * The requested period, or the default for anything not in PERIODS.
     */
    public static function period(mixed $days): int
    {
        $days = is_numeric($days) ? (int) $days : 0;

        return in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
    }

    /**
     * The last `$days` days up to today (display timezone), oldest first. The
     * grouping by local day happens in the database (PostgreSQL).
     *
     * @return array{days: int, statuses: list<array{value: string, label: string, tone: string}>, series: list<array{date: string, counts: array<string, int>}>}
     */
    public static function for(User $user, int $days): array
    {
        $firstDay = CarbonImmutable::parse(DisplayDate::today())->subDays($days - 1);
        $statuses = WorkOrderStatus::options();

        $rows = WorkOrder::query()
            ->visibleTo($user)
            ->where('created_at', '>=', DisplayDate::startOfDayUtc($firstDay->toDateString()))
            ->toBase()
            ->selectRaw("((created_at at time zone 'UTC') at time zone ?)::date as day", [DisplayDate::timezone()])
            ->selectRaw('status, count(*) as total')
            ->groupBy('day', 'status')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->day][(string) $row->status] = (int) $row->total;
        }

        $zero = array_fill_keys(array_column($statuses, 'value'), 0);
        $series = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $firstDay->addDays($offset)->toDateString();
            $series[] = ['date' => $date, 'counts' => array_replace($zero, array_intersect_key($counts[$date] ?? [], $zero))];
        }

        return ['days' => $days, 'statuses' => $statuses, 'series' => $series];
    }
}
