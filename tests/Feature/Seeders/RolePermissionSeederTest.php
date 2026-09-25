<?php

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
