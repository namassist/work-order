<?php

namespace App\Support\DailyReports;

use App\Http\Resources\AttachmentResource;
use App\Models\Media;
use App\Models\WorkOrder;
use App\Models\WorkOrderDailyReport;
use App\Models\WorkOrderStatusHistory;
use App\States\WorkOrder\Pelaksanaan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * The "Laporan Harian" section of the work order detail page (FLOW.md §7):
 * the reports newest first, the recent working days with their state, and
 * what the report form needs. Reports stay out of the timeline.
 */
class DailyReportPanel
{
    /**
     * How many working days the strip shows.
     */
    public const int STRIP_DAYS = 10;

    /**
     * @return list<array{id: int, report_date: string, note: string, links: list<string>, files: list<array<string, mixed>>, reporter: array{name: string}, editor: array{name: string}|null, created_at: string, updated_at: string|null, can_edit: bool}>
     */
    public static function reports(WorkOrder $workOrder, bool $mayReport, Request $request): array
    {
        $inExecution = $workOrder->status->equals(Pelaksanaan::class);

        return array_values($workOrder->dailyReports()
            ->with(['reporter', 'editor', 'files.uploader'])
            ->get()
            ->map(fn (WorkOrderDailyReport $report): array => [
                'id' => $report->id,
                'report_date' => $report->report_date->toDateString(),
                'note' => $report->note,
                'links' => $report->links,
                'files' => array_values($report->files->map(fn (Media $media): array => new AttachmentResource($media)->resolve($request))->all()),
                'reporter' => ['name' => $report->reporter->name],
                'editor' => $report->editor ? ['name' => $report->editor->name] : null,
                'created_at' => $report->created_at->toIso8601String(),
                'updated_at' => $report->updated_at?->toIso8601String(),
                'can_edit' => $mayReport && $inExecution && $report->isWithinEditWindow(),
            ])
            ->all());
    }

    /**
     * The last working days, oldest first, each reported, missing (in
     * Pelaksanaan at that day's cutoff, not the day it entered, and no
     * report), pending (today, before the cutoff, while a report is due),
     * or not required.
     *
     * @return list<array{date: string, state: 'reported'|'missing'|'pending'|'not_required'}>
     */
    public static function days(WorkOrder $workOrder): array
    {
        $days = ReportCalendar::recentWorkingDays(self::STRIP_DAYS);

        if ($days === []) {
            return [];
        }

        $reported = $workOrder->dailyReports()
            ->where('report_date', '>=', $days[0])
            ->pluck('report_date')
            ->map(fn (CarbonInterface $date): string => $date->toDateString())
            ->all();

        /** @var Collection<int, WorkOrderStatusHistory> $histories */
        $histories = $workOrder->statusHistories()->get(['to_status', 'created_at']);
        $execution = Pelaksanaan::getMorphClass();
        $enteredOn = $histories->where('to_status', $execution)
            ->map(fn (WorkOrderStatusHistory $history): string => ReportCalendar::dateOf($history->created_at))
            ->all();
        $today = ReportCalendar::today();
        $dueNow = $workOrder->status->equals(Pelaksanaan::class) && ! in_array($today, $enteredOn, true);

        return array_map(function (string $date) use ($reported, $histories, $workOrder, $execution, $enteredOn, $today, $dueNow): array {
            $cutoff = ReportCalendar::cutoffOf($date);

            $state = match (true) {
                in_array($date, $reported, true) => 'reported',
                $date === $today && now()->lessThan($cutoff) => $dueNow ? 'pending' : 'not_required',
                self::statusAt($histories, $workOrder, $cutoff) === $execution && ! in_array($date, $enteredOn, true) => 'missing',
                default => 'not_required',
            };

            return ['date' => $date, 'state' => $state];
        }, $days);
    }

    /**
     * What the report form needs: the dates it may use, and its limits.
     *
     * @return array{today: string, earliest_date: string, note_max_length: int, max_links: int, link_max_length: int, link_domains: list<string>, files: array<string, mixed>}
     */
    public static function settings(WorkOrder $workOrder): array
    {
        /** @var list<string> $domains */
        $domains = config()->array('work_order.daily_reports.links.domains');

        return [
            'today' => ReportCalendar::today(),
            'earliest_date' => max(ReportCalendar::earliestReportDate(), $workOrder->executionStartedOn() ?? ''),
            'note_max_length' => config()->integer('work_order.daily_reports.note_max_length'),
            'max_links' => config()->integer('work_order.daily_reports.links.max_links'),
            'link_max_length' => config()->integer('work_order.daily_reports.links.max_length'),
            'link_domains' => $domains,
            'files' => new WorkOrderDailyReport()->attachmentCollections()[WorkOrderDailyReport::FILES]->toFrontend(),
        ];
    }

    /**
     * The status the work order had at the moment: the last change before
     * it, or its current status when it has no history (factory data).
     *
     * @param  Collection<int, WorkOrderStatusHistory>  $histories  oldest first
     */
    private static function statusAt(Collection $histories, WorkOrder $workOrder, CarbonInterface $moment): ?string
    {
        if ($histories->isEmpty()) {
            return $workOrder->status->getValue();
        }

        return $histories->last(fn (WorkOrderStatusHistory $history): bool => $history->created_at->lessThanOrEqualTo($moment))?->to_status;
    }
}
