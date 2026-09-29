<?php

use App\Enums\CompanyScope;
use App\Enums\Permission;
use App\Enums\SystemRole;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role;

it('gives the admin role every permission, including ones added later', function () {
    $this->seed(RolePermissionSeeder::class);
    Role::findByName(SystemRole::Admin->value)->revokePermissionTo(Permission::UsersRestore->value);

    $this->seed(RolePermissionSeeder::class);

    expect(Role::findByName(SystemRole::Admin->value)->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all());
});

it('does not overwrite role permissions edited after the first run', function () {
    $this->seed(RolePermissionSeeder::class);
    Role::findByName('viewer')->syncPermissions([Permission::UsersView->value]);

    $this->seed(RolePermissionSeeder::class);

    expect(Role::findByName('viewer')->permissions->pluck('name')->all())->toBe([Permission::UsersView->value]);
});

it('keeps the activity log to the admin role', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::permission(Permission::ActivityLogView->value)->pluck('name')->all())->toBe([SystemRole::Admin->value]);
});

it('seeds the v2 roles by slug, with their labels, all for the executor company', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::query()->orderBy('name')->get()->mapWithKeys(fn (Role $role): array => [$role->name => [$role->label, $role->company_scope]])->all())->toBe([
        'admin' => ['Admin Sistem', 'executor'],
        'admin-wo' => ['Admin WO', 'executor'],
        'direktur' => ['Direktur', 'executor'],
        'finance' => ['Finance', 'executor'],
        'lead-operational' => ['Lead Operational', 'executor'],
        'pic-timesheet' => ['PIC Timesheet', 'executor'],
        'rental' => ['Rental', 'executor'],
        'viewer' => ['Viewer', 'executor'],
    ]);
});

it('gives each initial role exactly its work order permissions', function (string $role, array $permissions) {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::findByName($role)->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect($permissions)->map(fn (Permission $permission): string => $permission->value)->sort()->values()->all());
})->with([
    'Admin WO' => ['admin-wo', [Permission::WorkOrdersView, Permission::WorkOrdersCreate, Permission::WorkOrdersUpdate, Permission::WorkOrdersDelete, Permission::WorkOrdersComment, Permission::WorkOrdersExport]],
    'Lead Operational' => ['lead-operational', [Permission::WorkOrdersView, Permission::WorkOrdersProcess, Permission::WorkOrdersComment, Permission::WorkOrdersExport]],
    'PIC Timesheet' => ['pic-timesheet', [Permission::WorkOrdersView, Permission::WorkOrdersComment]],
    'Rental' => ['rental', [Permission::WorkOrdersView, Permission::WorkOrdersComment]],
    'Direktur' => ['direktur', [Permission::WorkOrdersView, Permission::WorkOrdersComment, Permission::WorkOrdersExport]],
    'Finance' => ['finance', [Permission::WorkOrdersView, Permission::WorkOrdersConfirmPayment, Permission::WorkOrdersComment, Permission::WorkOrdersExport]],
    'Viewer' => ['viewer', [Permission::WorkOrdersView]],
]);

it('seeds no role for client companies, and no v1 role', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::query()->where('company_scope', '!=', 'executor')->orWhereNull('company_scope')->exists())->toBeFalse()
        ->and(Role::query()->whereIn('name', ['pemohon', 'koordinator', 'pelaksana', 'keuangan'])->exists())->toBeFalse();
});

it('refuses to run while a v1 role still exists, and changes nothing', function (string $v1Role) {
    Role::create(['name' => $v1Role, 'guard_name' => 'web']);

    expect(fn () => $this->seed(RolePermissionSeeder::class))
        ->toThrow(LogicException::class, "The database still has the v1 roles [{$v1Role}].");

    expect(Role::query()->pluck('name')->all())->toBe([$v1Role]);
})->with(RolePermissionSeeder::V1_ROLES);

it('keeps an admin label edited after the first run', function () {
    $this->seed(RolePermissionSeeder::class);
    Role::findByName(SystemRole::Admin->value)->forceFill(['label' => 'Administrator'])->save();

    $this->seed(RolePermissionSeeder::class);

    expect(Role::findByName(SystemRole::Admin->value)->label)->toBe('Administrator');
});

it('makes every work order permission but view internal-only', function () {
    $workOrderPermissions = array_filter(Permission::cases(), fn (Permission $permission): bool => $permission->resource() === 'work-orders');

    foreach ($workOrderPermissions as $permission) {
        expect($permission->isInternalOnly())->toBe($permission !== Permission::WorkOrdersView, $permission->value);
    }
});

it('keeps the admin role for the executor company even after it was changed', function () {
    $this->seed(RolePermissionSeeder::class);
    Role::findByName(SystemRole::Admin->value)->forceFill(['company_scope' => null])->save();

    $this->seed(RolePermissionSeeder::class);

    expect(Role::findByName(SystemRole::Admin->value)->company_scope)->toBe('executor');
});

it('refuses to seed internal-only permissions on a role that is not for the executor company', function (Closure $seeder) {
    expect(fn () => $seeder()->run())->toThrow(LogicException::class, 'Initial role [campuran] is not an executor role and cannot hold internal-only permissions.');

    expect(Role::where('name', 'campuran')->exists())->toBeFalse();
})->with([
    'any company' => [fn (): RolePermissionSeeder => new class extends RolePermissionSeeder
    {
        protected const array INITIAL_ROLES = [
            'campuran' => ['label' => 'Campuran', 'scope' => null, 'permissions' => [Permission::WorkOrdersView, Permission::UsersView]],
        ];
    }],
    'client' => [fn (): RolePermissionSeeder => new class extends RolePermissionSeeder
    {
        protected const array INITIAL_ROLES = [
            'campuran' => ['label' => 'Campuran', 'scope' => CompanyScope::Client, 'permissions' => [Permission::WorkOrdersView, Permission::WorkOrdersComment]],
        ];
    }],
]);
