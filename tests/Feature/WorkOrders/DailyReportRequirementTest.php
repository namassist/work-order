<?php

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDailyReport;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Pelaksanaan → Review Dokumen needs a daily report (FLOW.md §5.1, §7);
| after Rental returned the work order for revision, a report created or
| edited after that return. The detail page shows why the button is
| disabled instead of letting the user try.
|
| "Now" is Tuesday 29 September 2026, 10:00 WITA (02:00 UTC).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-29 02:00', 'UTC'));
    $this->workOrder = enteredExecutionAt(WorkOrder::factory()->inProgress()->create(), '2026-09-23 03:00');
    $this->pic = userWithRole('pic-timesheet');
});

function submitForReview(WorkOrder $workOrder, User $user): TestResponse
{
    return test()->actingAs($user)->post(route('work-orders.transitions.store', $workOrder), ['status' => 'review_dokumen']);
}

/**
 * A report on the work order, created (and last updated) at the given UTC moment.
 */
function reportAt(WorkOrder $workOrder, string $date, string $utc): WorkOrderDailyReport
{
    $now = now();
    test()->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    $report = WorkOrderDailyReport::factory()->for($workOrder)->on($date)->create();
    test()->travelTo($now);

    return $report;
}

it('refuses review without any daily report', function () {
    submitForReview($this->workOrder, $this->pic)
        ->assertSessionHasErrors(['status' => 'Tambahkan minimal satu laporan harian sebelum mengajukan review dokumen.']);

    expect($this->workOrder->refresh()->status->getValue())->toBe('pelaksanaan');
});

it('allows review once the work order has a daily report', function () {
    reportAt($this->workOrder, '2026-09-28', '2026-09-28 05:00');

    submitForReview($this->workOrder, $this->pic)->assertSessionHasNoErrors();

    expect($this->workOrder->refresh()->status->getValue())->toBe('review_dokumen');
});

describe('after a return for revision', function () {
    beforeEach(function () {
        // Returned by Rental on Monday 28 September, 09:00 WITA.
        enteredExecutionAt($this->workOrder, '2026-09-28 01:00', 'review_dokumen');
    });

    it('does not count a report from before the return', function () {
        reportAt($this->workOrder, '2026-09-25', '2026-09-25 05:00');

        submitForReview($this->workOrder, $this->pic)
            ->assertSessionHasErrors(['status' => 'Work order dikembalikan untuk revisi pada 28 Sep 2026 09:00. Tambahkan atau perbarui laporan harian setelah itu sebelum mengajukan review dokumen.']);

        expect($this->workOrder->refresh()->status->getValue())->toBe('pelaksanaan');
    });

    it('counts a report created after the return', function () {
        reportAt($this->workOrder, '2026-09-25', '2026-09-25 05:00');
        reportAt($this->workOrder, '2026-09-28', '2026-09-28 06:00');

        submitForReview($this->workOrder, $this->pic)->assertSessionHasNoErrors();
    });

    it('counts a report edited after the return', function () {
        config(['work_order.daily_reports.edit_extra_days' => 1]);
        // Created at 08:30 WITA, half an hour before the return.
        $report = reportAt($this->workOrder, '2026-09-28', '2026-09-28 00:30');

        $this->actingAs($this->pic)
            ->patch(route('work-orders.daily-reports.update', [$this->workOrder, $report]), ['note' => 'Data dilengkapi sesuai catatan Rental.', 'links' => $report->links])
            ->assertSessionHasNoErrors();

        submitForReview($this->workOrder, $this->pic)->assertSessionHasNoErrors();

        expect($this->workOrder->refresh()->status->getValue())->toBe('review_dokumen');
    });
});

describe('on the detail page', function () {
    it('disables the review button with the reason while the requirement is not met', function () {
        $this->actingAs($this->pic)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('transitions.0.value', 'review_dokumen')
                ->where('transitions.0.blocked_reason', 'Tambahkan minimal satu laporan harian sebelum mengajukan review dokumen.'));
    });

    it('enables it once the requirement is met', function () {
        reportAt($this->workOrder, '2026-09-28', '2026-09-28 05:00');

        $this->actingAs($this->pic)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('transitions.0.value', 'review_dokumen')
                ->where('transitions.0.blocked_reason', null));
    });

    it('never blocks transitions without requirements', function () {
        $this->actingAs(userWithRole('lead-operational'))
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('transitions.0.value', 'dibatalkan')
                ->where('transitions.0.blocked_reason', null));
    });
});
