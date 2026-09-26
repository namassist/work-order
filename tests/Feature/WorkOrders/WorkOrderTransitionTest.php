<?php

use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-25 02:00', 'UTC'));
    $this->department = Department::factory()->create(['code' => 'IT']);
    $this->user = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersUpdate, Permission::ActivityLogView);
    $this->workOrder = WorkOrder::factory()->create(['department_id' => $this->department->id]);
});

it('numbers a draft on submission and records the change', function () {
    $this->actingAs($this->user)
        ->post(route('work-orders.transitions.store', $this->workOrder), ['status' => 'diajukan'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Work order WO/IT/2026/09/0001 sekarang diajukan.');

    expect($this->workOrder->refresh())
        ->number->toBe('WO/IT/2026/09/0001')
        ->status->getValue()->toBe('diajukan');
    expect($this->workOrder->statusHistories()->sole())
        ->from_status->toBe('draft')
        ->to_status->toBe('diajukan')
        ->user_id->toBe($this->user->id)
        ->note->toBeNull();
    expect(Activity::query()->forSubject($this->workOrder)->where('event', 'status_changed')->sole())
        ->causer_id->toBe($this->user->id)
        ->attribute_changes->toArray()->toBe([
            'attributes' => ['status' => 'diajukan', 'number' => 'WO/IT/2026/09/0001'],
            'old' => ['status' => 'draft', 'number' => null],
        ]);
});

it('shows the status change with labels in the history panel', function () {
    $this->travel(1)->minutes();
    app(TransitionWorkOrder::class)->handle($this->workOrder, 'diajukan', $this->user);

    $this->actingAs($this->user)
        ->getJson(route('admin.activity-log.history', ['work-order', $this->workOrder->id]))
        ->assertOk()
        ->assertJsonPath('data.0.event_label', 'Status diubah')
        ->assertJsonPath('data.0.subject.label', 'WO/IT/2026/09/0001')
        ->assertJsonPath('data.0.changes.0', ['field' => 'status', 'label' => 'Status', 'old' => 'Draft', 'new' => 'Diajukan']);
});

it('labels a draft subject as Draft in the activity log', function () {
    $activity = Activity::query()->forSubject($this->workOrder)->where('event', 'created')->sole();

    $this->actingAs(adminUser())
        ->getJson(route('admin.activity-log.history', ['work-order', $this->workOrder->id]))
        ->assertJsonPath('data.0.id', $activity->id)
        ->assertJsonPath('data.0.subject.label', 'Draft');
});

it('keeps the number when a submitted work order is cancelled', function () {
    $action = app(TransitionWorkOrder::class);
    $action->handle($this->workOrder, 'diajukan', $this->user);

    $action->handle($this->workOrder, 'dibatalkan', $this->user, 'Salah input');

    expect($this->workOrder->refresh())
        ->number->toBe('WO/IT/2026/09/0001')
        ->status->getValue()->toBe('dibatalkan')
        ->and($this->workOrder->statusHistories()->reorder()->latest('id')->first())
        ->from_status->toBe('diajukan')
        ->note->toBe('Salah input');
});

it('cancels a draft without numbering it', function () {
    app(TransitionWorkOrder::class)->handle($this->workOrder, 'dibatalkan', $this->user, 'Tidak jadi');

    expect($this->workOrder->refresh())
        ->number->toBeNull()
        ->status->getValue()->toBe('dibatalkan');
});

it('requires a note to cancel', function () {
    $this->actingAs($this->user)
        ->post(route('work-orders.transitions.store', $this->workOrder), ['status' => 'dibatalkan'])
        ->assertSessionHasErrors(['note' => 'Catatan wajib diisi.']);

    expect($this->workOrder->refresh()->status->getValue())->toBe('draft');
});

it('rejects a transition the current status does not allow', function (string $from, string $to) {
    $workOrder = WorkOrder::factory()->create(['department_id' => $this->department->id, 'status' => $from]);

    $this->actingAs($this->user)
        ->post(route('work-orders.transitions.store', $workOrder), ['status' => $to, 'note' => 'x'])
        ->assertSessionHasErrors('status');

    expect($workOrder->refresh()->status->getValue())->toBe($from)
        ->and($workOrder->statusHistories()->count())->toBe(0);
})->with([
    'back to draft' => ['diajukan', 'draft'],
    'out of cancelled' => ['dibatalkan', 'diajukan'],
    'same status' => ['draft', 'draft'],
    'unknown status' => ['draft', 'disetujui'],
]);

it('refuses a transition that became invalid after the page loaded', function () {
    $stale = WorkOrder::find($this->workOrder->id);
    app(TransitionWorkOrder::class)->handle($this->workOrder, 'dibatalkan', $this->user, 'Batal');

    expect(fn () => app(TransitionWorkOrder::class)->handle($stale, 'diajukan', $this->user))
        ->toThrow(CouldNotPerformTransition::class);
    expect($this->workOrder->refresh())
        ->number->toBeNull()
        ->status->getValue()->toBe('dibatalkan');
});

it('forbids transitions without the update permission', function () {
    $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
        ->post(route('work-orders.transitions.store', $this->workOrder), ['status' => 'diajukan'])
        ->assertForbidden();
});
