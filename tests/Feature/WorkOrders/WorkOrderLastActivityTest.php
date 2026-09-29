<?php

use App\Actions\Attachments\AddAttachment;
use App\Actions\Attachments\RemoveAttachment;
use App\Actions\WorkOrders\AddWorkOrderComment;
use App\Actions\WorkOrders\DeleteWorkOrderComment;
use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Actions\WorkOrders\UpdateWorkOrderComment;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
 * A work order's updated_at is its last activity (the dashboard's "WO
 * Terbaru" and the list's "Terakhir diperbarui" sort). Comments, status
 * changes, and attachments bump it, and bumping it never adds an audit
 * entry of its own.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-25 02:00', 'UTC'));
    $this->department = Department::factory()->client()->create(['code' => 'IT']);
    $this->user = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersUpdate, Permission::WorkOrdersComment);
});

/**
 * Create the work order under test and start watching its audit entries an hour later.
 */
function watchWorkOrder(WorkOrder $workOrder): WorkOrder
{
    test()->workOrder = $workOrder;
    test()->lastActivityId = (int) Activity::query()->max('id');
    test()->travel(1)->hours();

    return $workOrder;
}

/**
 * The audit events written on the watched work order since watching began.
 *
 * @return list<string>
 */
function newWorkOrderEvents(): array
{
    return Activity::query()
        ->forSubject(test()->workOrder)
        ->where('id', '>', test()->lastActivityId)
        ->orderBy('id')
        ->pluck('event')
        ->all();
}

function expectTouchedNow(): void
{
    expect(test()->workOrder->refresh()->updated_at->toIso8601String())->toBe(now()->toIso8601String());
}

it('bumps the work order when a comment is posted, edited, or deleted', function () {
    $workOrder = watchWorkOrder(WorkOrder::factory()->submitted()->create(['requester_department_id' => $this->department->id]));

    $comment = app(AddWorkOrderComment::class)->handle($workOrder, $this->user, 'Mohon dicek.');
    expectTouchedNow();

    $this->travel(1)->minutes();
    app(UpdateWorkOrderComment::class)->handle($comment, $this->user, 'Mohon segera dicek.');
    expectTouchedNow();

    $this->travel(1)->minutes();
    app(DeleteWorkOrderComment::class)->handle($comment, $this->user);
    expectTouchedNow();

    expect(newWorkOrderEvents())->toBe(['comment_added', 'comment_edited', 'comment_deleted']);
});

it('bumps the work order on a status change', function () {
    $workOrder = watchWorkOrder(WorkOrder::factory()->targeting(Department::factory()->create())->create(['requester_department_id' => $this->department->id]));

    app(TransitionWorkOrder::class)->handle($workOrder, 'diajukan', adminUser());

    expectTouchedNow();
    expect(newWorkOrderEvents())->toBe(['status_changed']);
});

it('bumps the work order when a document is added or removed', function () {
    Storage::fake('attachments');
    $workOrder = watchWorkOrder(WorkOrder::factory()->create(['requester_department_id' => $this->department->id]));

    $media = app(AddAttachment::class)->handle($workOrder, $workOrder->documentsCollection(), attachmentUpload('dokumen.pdf'), $this->user);
    expectTouchedNow();

    $this->travel(1)->minutes();
    app(RemoveAttachment::class)->handle($workOrder, $media);
    expectTouchedNow();

    expect(newWorkOrderEvents())->toBe(['attachment_added', 'attachment_removed']);
});

it('logs nothing when only the work order is touched', function () {
    $workOrder = watchWorkOrder(WorkOrder::factory()->create(['requester_department_id' => $this->department->id]));

    $workOrder->touch();

    expectTouchedNow();
    expect(newWorkOrderEvents())->toBe([]);
});
