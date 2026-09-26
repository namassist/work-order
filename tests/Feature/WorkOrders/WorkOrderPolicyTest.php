<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Gate;

it('lets a user see only their own department\'s work orders', function () {
    $own = Department::factory()->create();
    $user = userInDepartment($own, Permission::WorkOrdersView);

    expect($user->can('view', WorkOrder::factory()->create(['department_id' => $own->id])))->toBeTrue()
        ->and($user->can('view', WorkOrder::factory()->create()))->toBeFalse();
});

it('lets work-orders.view-all see every department', function () {
    $user = userInDepartment(Department::factory()->create(), Permission::WorkOrdersView, Permission::WorkOrdersViewAll);

    expect($user->can('view', WorkOrder::factory()->create()))->toBeTrue();
});

it('shows a user without a department no work orders', function () {
    $user = userWithPermissions(Permission::WorkOrdersView);
    WorkOrder::factory()->create();

    expect($user->can('view', WorkOrder::factory()->create()))->toBeFalse()
        ->and(WorkOrder::visibleTo($user)->count())->toBe(0);
});

it('limits the visibleTo scope to the same work orders as the policy', function () {
    $own = Department::factory()->create();
    $mine = WorkOrder::factory()->create(['department_id' => $own->id]);
    WorkOrder::factory()->create();

    expect(WorkOrder::visibleTo(userInDepartment($own, Permission::WorkOrdersView))->pluck('id')->all())->toBe([$mine->id])
        ->and(WorkOrder::visibleTo(userInDepartment($own, Permission::WorkOrdersViewAll))->count())->toBe(2);
});

it('answers 404 rather than 403 for another department\'s work order', function (string $ability) {
    $user = userInDepartment(Department::factory()->create(), ...Permission::cases());
    $user->revokePermissionTo(Permission::WorkOrdersViewAll->value);

    $response = Gate::forUser($user)->inspect($ability, WorkOrder::factory()->create());

    expect($response->allowed())->toBeFalse()
        ->and($response->status())->toBe(404);
})->with(['view', 'update', 'transition', 'delete', 'restore']);

it('allows editing only while the work order is a draft', function () {
    $department = Department::factory()->create();
    $user = userInDepartment($department, Permission::WorkOrdersUpdate);

    expect($user->can('update', WorkOrder::factory()->create(['department_id' => $department->id])))->toBeTrue()
        ->and($user->can('update', WorkOrder::factory()->submitted()->create(['department_id' => $department->id])))->toBeFalse()
        ->and($user->can('update', WorkOrder::factory()->cancelled()->create(['department_id' => $department->id])))->toBeFalse();
});

it('requires a department to create work orders', function () {
    expect(userWithPermissions(Permission::WorkOrdersCreate)->can('create', WorkOrder::class))->toBeFalse()
        ->and(userInDepartment(Department::factory()->create(), Permission::WorkOrdersCreate)->can('create', WorkOrder::class))->toBeTrue();
});

it('grants each action only with its permission', function (string $ability, Permission $permission) {
    $department = Department::factory()->create();
    $workOrder = WorkOrder::factory()->create(['department_id' => $department->id]);
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
