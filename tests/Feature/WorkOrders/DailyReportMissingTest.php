<?php

use App\Actions\WorkOrders\AddDailyReport;
use App\Models\WorkOrder;
use App\Models\WorkOrderDailyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| "Belum lapor" (FLOW.md §7): a work order in Pelaksanaan without a report
| dated today, once today's cutoff (17:00 WITA) has passed on a working day.
| The day it entered Pelaksanaan (approval or a return for revision) is
| exempt. Computed at query time; a daily signal of its own, not an overdue
| basis (FLOW.md §11).
|
| The work order entered Pelaksanaan on Wednesday 23 September 2026, 11:00
| WITA. "Now" is Tuesday 29 September, 18:00 WITA (10:00 UTC) unless a test
| moves the clock.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00', 'UTC'));
    $this->workOrder = enteredExecutionAt(WorkOrder::factory()->inProgress()->create(), '2026-09-23 03:00');
});

/**
 * Asserts that the model check and the query scope agree on the work order.
 */
function expectMissingReport(WorkOrder $workOrder, bool $missing): void
{
    expect($workOrder->fresh()?->isMissingDailyReport())->toBe($missing)
        ->and(WorkOrder::query()->missingDailyReport()->whereKey($workOrder->id)->exists())->toBe($missing);
}

describe('the flag', function () {
    it('is raised after the cutoff on a working day without a report for today', function () {
        WorkOrderDailyReport::factory()->for($this->workOrder)->on('2026-09-28')->create();

        expectMissingReport($this->workOrder, true);
    });

    it('is not raised once today is reported', function () {
        WorkOrderDailyReport::factory()->for($this->workOrder)->on('2026-09-29')->create();

        expectMissingReport($this->workOrder, false);
    });

    it('waits for the cutoff', function (string $utc, bool $missing) {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

        expectMissingReport($this->workOrder, $missing);
    })->with([
        '16:59 WITA' => ['2026-09-29 08:59', false],
        '17:00 WITA' => ['2026-09-29 09:00', true],
        // 07:30 WITA on Wednesday: Tuesday's report no longer matters, Wednesday's is not due yet.
        '23:30 UTC, the next WITA morning' => ['2026-09-29 23:30', false],
    ]);

    it('follows the cutoff setting', function () {
        config(['work_order.daily_reports.cutoff' => '12:00']);
        $this->travelTo(CarbonImmutable::parse('2026-09-29 05:00', 'UTC'));

        expectMissingReport($this->workOrder, true);
    });

    it('is never raised on days that are not working days', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'UTC'));

        expectMissingReport($this->workOrder, false);
    });

    it('is never raised on a configured holiday', function () {
        config(['work_order.daily_reports.holidays' => ['2026-09-29']]);

        expectMissingReport($this->workOrder, false);
    });

    it('spares the day the work order entered Pelaksanaan', function () {
        $workOrder = enteredExecutionAt(WorkOrder::factory()->inProgress()->create(), '2026-09-29 08:55');

        expectMissingReport($workOrder, false);
    });

    it('spares the day of a return for revision, and not the day after', function (string $returnedAt, bool $missing) {
        enteredExecutionAt($this->workOrder, $returnedAt, 'review_dokumen');

        expectMissingReport($this->workOrder, $missing);
    })->with([
        'returned today' => ['2026-09-29 02:00', false],
        'returned yesterday' => ['2026-09-28 02:00', true],
    ]);

    it('is raised only in Pelaksanaan', function (string $state) {
        $workOrder = enteredExecutionAt(WorkOrder::factory()->{$state}()->create(), '2026-09-23 03:00');

        expectMissingReport($workOrder, false);
    })->with(['submitted', 'inReview', 'awaitingBastApproval', 'closed']);
});

describe('the list', function () {
    beforeEach(function () {
        $this->reported = enteredExecutionAt(WorkOrder::factory()->inProgress()->create(), '2026-09-23 03:00');
        WorkOrderDailyReport::factory()->for($this->reported)->create();
        $this->viewer = userWithRole('viewer');
    });

    it('marks the rows that are missing today\'s report', function () {
        $expected = [$this->workOrder->id => true, $this->reported->id => false];
        ksort($expected);

        $this->actingAs($this->viewer)
            ->get(route('work-orders.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('workOrders.data', fn ($rows): bool => collect($rows)->pluck('missing_daily_report', 'id')->sortKeys()->all() === $expected));
    });

    it('filters to the work orders missing today\'s report', function () {
        $this->actingAs($this->viewer)
            ->get(route('work-orders.index', ['missing_report' => 1]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('filters.missing_report', true)
                ->has('workOrders.data', 1)
                ->where('workOrders.data.0.id', $this->workOrder->id));
    });

    it('exports with the same filter and names it in the log', function () {
        $this->actingAs(userWithRole('admin-wo'))
            ->get(route('work-orders.export', ['missing_report' => 1]))
            ->assertOk();

        expect(Activity::query()->where('event', 'exported')->sole()->properties['filter'])->toBe('Belum lapor: Ya');
    });
});

it('counts the work orders missing today\'s report on the dashboard', function () {
    WorkOrderDailyReport::factory()->for(enteredExecutionAt(WorkOrder::factory()->inProgress()->create(), '2026-09-23 03:00'))->create();

    $this->actingAs(userWithRole('viewer'))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->loadDeferredProps(fn (Assert $reload): AssertableInertia => $reload
                ->where('workOrderCounts.in_progress', 2)
                ->where('workOrderCounts.missing_report', 1)));
});

describe('the detail page', function () {
    it('flags the work order and shows recent working days as reported, missing, or not required', function () {
        WorkOrderDailyReport::factory()->for($this->workOrder)->on('2026-09-24')->create();
        WorkOrderDailyReport::factory()->for($this->workOrder)->on('2026-09-28')->create();

        $this->actingAs(userWithRole('viewer'))
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('missingDailyReport', true)
                ->where('dailyReportDays', [
                    ['date' => '2026-09-16', 'state' => 'not_required'],
                    ['date' => '2026-09-17', 'state' => 'not_required'],
                    ['date' => '2026-09-18', 'state' => 'not_required'],
                    ['date' => '2026-09-21', 'state' => 'not_required'],
                    ['date' => '2026-09-22', 'state' => 'not_required'],
                    // Entered Pelaksanaan that day.
                    ['date' => '2026-09-23', 'state' => 'not_required'],
                    ['date' => '2026-09-24', 'state' => 'reported'],
                    ['date' => '2026-09-25', 'state' => 'missing'],
                    ['date' => '2026-09-28', 'state' => 'reported'],
                    ['date' => '2026-09-29', 'state' => 'missing'],
                ]));
    });

    it('shows today as pending before the cutoff', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-29 02:00', 'UTC'));

        $this->actingAs(userWithRole('viewer'))
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('missingDailyReport', false)
                ->where('dailyReportDays.9', ['date' => '2026-09-29', 'state' => 'pending']));
    });

    it('lists the reports newest first with their reporter, files, and links', function () {
        $pic = userWithRole('pic-timesheet');
        WorkOrderDailyReport::factory()->for($this->workOrder)->on('2026-09-24')->create(['created_by' => $pic->id, 'note' => 'Hari pertama.']);
        WorkOrderDailyReport::factory()->for($this->workOrder)->on('2026-09-28')->create(['created_by' => $pic->id, 'note' => 'Hari kedua.', 'links' => ['https://unggul.sharepoint.com/x']]);

        $this->actingAs($pic)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('dailyReports', 2)
                ->where('dailyReports.0.report_date', '2026-09-28')
                ->where('dailyReports.0.note', 'Hari kedua.')
                ->where('dailyReports.0.links', ['https://unggul.sharepoint.com/x'])
                ->where('dailyReports.0.reporter.name', $pic->name)
                ->where('dailyReports.0.files', [])
                ->where('dailyReports.1.report_date', '2026-09-24')
                ->where('can.report', true)
                ->where('dailyReportSettings.earliest_date', '2026-09-25')
                ->where('dailyReportSettings.today', '2026-09-29'));
    });

    it('loads report files and their uploaders without a query per file', function () {
        $pic = userWithRole('pic-timesheet');
        foreach (['2026-09-24', '2026-09-25', '2026-09-28'] as $date) {
            $this->travelTo(CarbonImmutable::parse($date.' 05:00', 'UTC'));
            app(AddDailyReport::class)->handle($this->workOrder, $pic, ['report_date' => $date, 'note' => 'Progres.', 'links' => []], [attachmentUpload('anggaran.xlsx'), attachmentUpload('dokumen.pdf')]);
        }
        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00', 'UTC'));

        DB::enableQueryLog();
        $this->actingAs($pic)->get(route('work-orders.show', $this->workOrder))->assertOk();
        $userQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "users"'))->count();

        // The viewer, the WO's people, and the reports' people: a fixed number, not one per file.
        expect($userQueries)->toBeLessThanOrEqual(5);
    });

    it('lets only report holders post, and only in Pelaksanaan', function () {
        $this->actingAs(userWithRole('viewer'))
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('can.report', false));

        $this->actingAs(userWithRole('pic-timesheet'))
            ->get(route('work-orders.show', WorkOrder::factory()->inReview()->create()))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('can.report', false)->where('missingDailyReport', false));
    });
});
