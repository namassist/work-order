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
        ->and($user->can('view', WorkOrder::factory()->submitted()->create()))->toBeFalse();
});

it('lets an Unggul user with work-orders.view see every submitted work order, and drafts only with work-orders.create', function () {
    $submitted = WorkOrder::factory()->submitted()->create();
    $draft = WorkOrder::factory()->create();
    $reader = unggulUser(Permission::WorkOrdersView);
    $creator = unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersCreate);

    expect($reader->can('view', $submitted))->toBeTrue()
        ->and($reader->can('view', $draft))->toBeFalse()
        ->and($creator->can('view', $draft))->toBeTrue();
});

it('shows nothing to an Unggul user without work-orders.view, drafts included', function () {
    $user = unggulUser(Permission::WorkOrdersCreate, Permission::WorkOrdersUpdate);
    WorkOrder::factory()->create();
    WorkOrder::factory()->submitted()->create();

    expect(WorkOrder::visibleTo($user)->count())->toBe(0)
        ->and(WorkOrder::query()->get()->filter(fn (WorkOrder $workOrder): bool => $workOrder->isVisibleTo($user)))->toBeEmpty();
});

it('limits the visibleTo scope to the same work orders as the policy', function () {
    $own = Department::factory()->client()->create();
    $mine = WorkOrder::factory()->create(['requester_department_id' => $own->id]);
    $draft = WorkOrder::factory()->create();
    $submitted = WorkOrder::factory()->submitted()->create();

    expect(WorkOrder::visibleTo(userInDepartment($own, Permission::WorkOrdersView))->pluck('id')->all())->toBe([$mine->id])
        ->and(WorkOrder::visibleTo(unggulUser(Permission::WorkOrdersView))->pluck('id')->all())->toBe([$submitted->id])
        ->and(WorkOrder::visibleTo(unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersCreate))->orderBy('id')->pluck('id')->all())->toBe([$mine->id, $draft->id, $submitted->id]);
});

it('answers 404 rather than 403 for a work order the user cannot see', function (string $ability, array $arguments) {
    $user = unggulUser(...Permission::cases());
    $user->revokePermissionTo(Permission::WorkOrdersCreate->value);

    $response = Gate::forUser($user)->inspect($ability, [WorkOrder::factory()->create(), ...$arguments]);

    expect($response->allowed())->toBeFalse()
        ->and($response->status())->toBe(404);
})->with([
    'view' => ['view', []],
    'update' => ['update', []],
    'transition' => ['transition', ['diajukan']],
    'changeStatus' => ['changeStatus', []],
    'delete' => ['delete', []],
    'restore' => ['restore', []],
]);

it('allows editing only while the work order is editable (Draft and Ditolak)', function () {
    $user = unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersCreate, Permission::WorkOrdersUpdate);

    expect($user->can('update', WorkOrder::factory()->create()))->toBeTrue()
        ->and($user->can('update', WorkOrder::factory()->rejected()->create()))->toBeTrue()
        ->and($user->can('update', WorkOrder::factory()->submitted()->create()))->toBeFalse()
        ->and($user->can('update', WorkOrder::factory()->inProgress()->create()))->toBeFalse()
        ->and($user->can('update', WorkOrder::factory()->cancelled()->create()))->toBeFalse();
});

it('never lets a client company user edit, whatever they hold', function () {
    $department = Department::factory()->client()->create();
    $user = userInDepartment($department, ...Permission::cases());

    expect($user->can('update', WorkOrder::factory()->create(['requester_department_id' => $department->id])))->toBeFalse();
});

it('allows changing attachments only on drafts, with work-orders.update', function () {
    $draft = WorkOrder::factory()->create();
    $submitted = WorkOrder::factory()->submitted()->create();
    $updater = unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersCreate, Permission::WorkOrdersUpdate);
    $others = array_filter(Permission::cases(), fn (Permission $case): bool => $case !== Permission::WorkOrdersUpdate);
    $viewer = unggulUser(...$others);

    foreach (['addAttachment' => 'dokumen', 'deleteAttachment' => new Media()->forceFill(['collection_name' => WorkOrder::DOCUMENTS])] as $ability => $argument) {
        expect($updater->can($ability, [$draft, $argument]))->toBeTrue()
            ->and($updater->can($ability, [$submitted, $argument]))->toBeFalse()
            ->and($viewer->can($ability, [$draft, $argument]))->toBeFalse();
    }
});

it('answers 404 for attachment changes on a work order the user cannot see', function (string $ability, Closure $argument) {
    $user = unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersUpdate);

    $response = Gate::forUser($user)->inspect($ability, [WorkOrder::factory()->create(), $argument()]);

    expect($response->status())->toBe(404);
})->with([
    'addAttachment' => ['addAttachment', fn (): string => 'dokumen'],
    'deleteAttachment' => ['deleteAttachment', fn (): Media => new Media],
]);

it('grants each action only with its permission', function (string $ability, Permission $permission, array $arguments = []) {
    $workOrder = WorkOrder::factory()->create();
    // Seeing the draft needs work-orders.view and work-orders.create.
    $sees = [Permission::WorkOrdersView, Permission::WorkOrdersCreate];
    $others = array_filter(Permission::cases(), fn (Permission $case): bool => $case !== $permission);

    expect(unggulUser(...$sees, ...[$permission])->can($ability, [$workOrder, ...$arguments]))->toBeTrue()
        ->and(unggulUser(...$others)->can($ability, [$workOrder, ...$arguments]))->toBeFalse();
})->with([
    'update' => ['update', Permission::WorkOrdersUpdate],
    'transition' => ['transition', Permission::WorkOrdersUpdate, ['diajukan']],
    'delete' => ['delete', Permission::WorkOrdersDelete],
    'restore' => ['restore', Permission::WorkOrdersRestore],
]);

it('never allows permanent deletion', function () {
    expect(adminUser()->can('forceDelete', WorkOrder::factory()->create()))->toBeFalse();
});
