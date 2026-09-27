<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Models\Media;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Gate;

it('lets an IC user see only their own department\'s work orders', function () {
    $own = Department::factory()->client()->create();
    $user = userInDepartment($own, Permission::WorkOrdersView);

    expect($user->can('view', WorkOrder::factory()->create(['requester_department_id' => $own->id])))->toBeTrue()
        ->and($user->can('view', WorkOrder::factory()->create()))->toBeFalse();
});

it('lets an Unggul user with work-orders.view-all see every submitted work order, but no one else\'s draft', function () {
    $user = unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersViewAll);

    expect($user->can('view', WorkOrder::factory()->submitted()->create()))->toBeTrue()
        ->and($user->can('view', WorkOrder::factory()->create()))->toBeFalse();
});

it('ignores work-orders.view-all for a client company user', function () {
    $own = Department::factory()->client()->create();
    $user = userInDepartment($own, Permission::WorkOrdersView, Permission::WorkOrdersViewAll);
    $mine = WorkOrder::factory()->create(['requester_department_id' => $own->id]);
    $other = WorkOrder::factory()->create();

    expect($user->can('view', $other))->toBeFalse()
        ->and($user->can('viewAllDepartments', WorkOrder::class))->toBeFalse()
        ->and(WorkOrder::visibleTo($user)->pluck('id')->all())->toBe([$mine->id]);
});

it('limits the visibleTo scope to the same work orders as the policy', function () {
    $own = Department::factory()->client()->create();
    $mine = WorkOrder::factory()->create(['requester_department_id' => $own->id]);
    WorkOrder::factory()->create();

    $submitted = WorkOrder::factory()->submitted()->create();

    expect(WorkOrder::visibleTo(userInDepartment($own, Permission::WorkOrdersView))->pluck('id')->all())->toBe([$mine->id])
        ->and(WorkOrder::visibleTo(unggulUser(Permission::WorkOrdersViewAll))->pluck('id')->all())->toBe([$submitted->id]);
});

it('answers 404 rather than 403 for another department\'s work order', function (string $ability) {
    $user = userInDepartment(Department::factory()->client()->create(), ...Permission::cases());
    $user->revokePermissionTo(Permission::WorkOrdersViewAll->value);

    $response = Gate::forUser($user)->inspect($ability, WorkOrder::factory()->create());

    expect($response->allowed())->toBeFalse()
        ->and($response->status())->toBe(404);
})->with(['view', 'update', 'transition', 'delete', 'restore']);

it('allows editing only while the work order is a draft', function () {
    $department = Department::factory()->client()->create();
    $user = userInDepartment($department, Permission::WorkOrdersUpdate);

    expect($user->can('update', WorkOrder::factory()->create(['requester_department_id' => $department->id])))->toBeTrue()
        ->and($user->can('update', WorkOrder::factory()->submitted()->create(['requester_department_id' => $department->id])))->toBeFalse()
        ->and($user->can('update', WorkOrder::factory()->cancelled()->create(['requester_department_id' => $department->id])))->toBeFalse();
});

it('allows changing attachments only on drafts, with work-orders.update', function () {
    $department = Department::factory()->client()->create();
    $draft = WorkOrder::factory()->create(['requester_department_id' => $department->id]);
    $submitted = WorkOrder::factory()->submitted()->create(['requester_department_id' => $department->id]);
    $updater = userInDepartment($department, Permission::WorkOrdersUpdate);
    $others = array_filter(Permission::cases(), fn (Permission $case): bool => $case !== Permission::WorkOrdersUpdate);
    $viewer = userInDepartment($department, ...$others);

    foreach (['addAttachment' => 'dokumen', 'deleteAttachment' => new Media] as $ability => $argument) {
        expect($updater->can($ability, [$draft, $argument]))->toBeTrue()
            ->and($updater->can($ability, [$submitted, $argument]))->toBeFalse()
            ->and($viewer->can($ability, [$draft, $argument]))->toBeFalse();
    }
});

it('answers 404 for attachment changes on another department\'s work order', function (string $ability, Closure $argument) {
    $user = userInDepartment(Department::factory()->client()->create(), Permission::WorkOrdersUpdate);

    $response = Gate::forUser($user)->inspect($ability, [WorkOrder::factory()->create(), $argument()]);

    expect($response->status())->toBe(404);
})->with([
    'addAttachment' => ['addAttachment', fn (): string => 'dokumen'],
    'deleteAttachment' => ['deleteAttachment', fn (): Media => new Media],
]);

it('grants each action only with its permission', function (string $ability, Permission $permission) {
    $department = Department::factory()->client()->create();
    $workOrder = WorkOrder::factory()->create(['requester_department_id' => $department->id]);
    $others = array_filter(Permission::cases(), fn (Permission $case): bool => $case !== $permission);

    expect(userInDepartment($department, $permission)->can($ability, $workOrder))->toBeTrue()
        ->and(userInDepartment($department, ...$others)->can($ability, $workOrder))->toBeFalse();
})->with([
    'view' => ['view', Permission::WorkOrdersView],
    'update' => ['update', Permission::WorkOrdersUpdate],
    'transition' => ['transition', Permission::WorkOrdersUpdate],
    'delete' => ['delete', Permission::WorkOrdersDelete],
    'restore' => ['restore', Permission::WorkOrdersRestore],
]);

it('never allows permanent deletion', function () {
    expect(adminUser()->can('forceDelete', WorkOrder::factory()->create()))->toBeFalse();
});
