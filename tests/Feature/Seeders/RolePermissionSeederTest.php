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

it('lets only admin and keuangan see work orders of every department at first', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::permission(Permission::WorkOrdersViewAll->value)->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'keuangan']);
});

it('lets only admin, pelaksana, and keuangan export work orders at first', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::permission(Permission::WorkOrdersExport->value)->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'keuangan', 'pelaksana']);
});

it('lets every initial role except viewer comment on work orders at first', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::permission(Permission::WorkOrdersComment->value)->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'keuangan', 'koordinator', 'pelaksana', 'pemohon']);
});

it('seeds each initial role for the companies it fits', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::query()->orderBy('name')->pluck('company_scope', 'name')->all())->toBe([
        'admin' => 'executor',
        'keuangan' => 'executor',
        'koordinator' => 'executor',
        'pelaksana' => 'executor',
        'pemohon' => 'client',
        'viewer' => null,
    ]);
});

it('gives internal-only permissions to executor roles only', function () {
    $this->seed(RolePermissionSeeder::class);

    $holders = Role::query()->with('permissions')->get()
        ->filter(fn (Role $role): bool => $role->permissions->pluck('name')->intersect(Permission::internalOnlyValues())->isNotEmpty());

    expect($holders->pluck('name')->sort()->values()->all())->toBe(['admin', 'keuangan', 'koordinator', 'pelaksana'])
        ->and($holders->every(fn (Role $role): bool => $role->company_scope === 'executor'))->toBeTrue();
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
            'campuran' => ['scope' => null, 'permissions' => [Permission::WorkOrdersView, Permission::UsersView]],
        ];
    }],
    'client' => [fn (): RolePermissionSeeder => new class extends RolePermissionSeeder
    {
        protected const array INITIAL_ROLES = [
            'campuran' => ['scope' => CompanyScope::Client, 'permissions' => [Permission::WorkOrdersView, Permission::WorkOrdersViewAll]],
        ];
    }],
]);
