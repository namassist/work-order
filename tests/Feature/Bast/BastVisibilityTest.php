<?php

use App\Enums\Permission;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBast;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| BAST files follow the work order's visibility (FLOW.md §6) and, like daily
| reports, are internal (Unggul) data: never shown to client company users,
| even of the requesting department.
*/

beforeEach(function () {
    Storage::fake('attachments');
    activeBastTemplate();
    $this->workOrder = WorkOrder::factory()->inReview()->create();

    $this->actingAs(userWithRole('rental'))->post(route('work-orders.transitions.store', $this->workOrder), ['status' => 'approval_bast']);
    $this->actingAs(userWithRole('direktur'))->post(route('work-orders.transitions.store', $this->workOrder), ['status' => 'bast_disetujui']);

    $this->bast = $this->workOrder->refresh()->bast;
    expect($this->bast->isApproved())->toBeTrue();
});

/**
 * @return array<string, Media>
 */
function bastFiles(WorkOrderBast $bast): array
{
    return ['draf' => $bast->draftFile, 'final' => $bast->finalFile];
}

it('lets every internal role that sees the work order open both PDFs', function (string $role) {
    $user = userWithRole($role);

    foreach (bastFiles($this->bast) as $file) {
        $this->actingAs($user)->get(route('attachments.show', $file->uuid))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    $this->actingAs($user)->get(route('work-orders.show', $this->workOrder))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('bast.number', $this->bast->number)
            ->where('bast.final_sha256', $this->bast->final_sha256)
            ->where('bast.approver_name', $this->bast->approver_name)
            ->where('bast.file.id', $this->bast->finalFile->uuid));
})->with(['admin-wo', 'lead-operational', 'pic-timesheet', 'rental', 'direktur', 'finance', 'viewer', 'admin']);

it('answers 404 to an internal user without work-orders.view', function () {
    $user = userWithPermissions(Permission::WorkOrdersComment);

    foreach (bastFiles($this->bast) as $file) {
        $this->actingAs($user)->get(route('attachments.show', $file->uuid))->assertNotFound();
    }
});

it('hides the BAST from client company users, even of the requesting department', function (Closure $makeUser) {
    /** @var User $user */
    $user = $makeUser();
    $this->workOrder->update(['requester_department_id' => $user->department_id]);

    foreach (bastFiles($this->bast) as $file) {
        $this->actingAs($user)->get(route('attachments.show', $file->uuid))->assertNotFound();
    }

    $this->actingAs($user)->get(route('work-orders.show', $this->workOrder))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('bast', null));
})->with([
    'viewer' => [fn (): User => icUser(Permission::WorkOrdersView)],
    'every permission' => [fn (): User => icUser(...Permission::cases())],
    'admin role' => [fn (): User => icUser()->assignRole('admin')],
]);

it('answers 404 once the work order is deleted', function () {
    $this->workOrder->delete();

    foreach (bastFiles($this->bast) as $file) {
        $this->actingAs(adminUser())->get(route('attachments.show', $file->uuid))->assertNotFound();
    }
});

it('shows the draft before approval', function () {
    $workOrder = WorkOrder::factory()->inReview()->create();
    $this->actingAs(userWithRole('rental'))->post(route('work-orders.transitions.store', $workOrder), ['status' => 'approval_bast']);

    $this->actingAs(userWithRole('viewer'))->get(route('work-orders.show', $workOrder))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('bast.approved_at', null)
            ->where('bast.final_sha256', null)
            ->where('bast.file.id', $workOrder->refresh()->bast->draftFile->uuid));
});
