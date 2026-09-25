<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

describe('index', function () {
    it('lists roles with their user counts', function () {
        $admin = adminUser();
        User::factory()->count(2)->create()->each->assignRole('viewer');

        $this->actingAs($admin)
            ->get(route('admin.roles.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->component('admin/roles/Index')
                ->has('roles', 5)
                ->where('roles.4.name', 'viewer')
                ->where('roles.4.users_count', 2));
    });
});

describe('store', function () {
    it('creates a role with permissions', function () {
        $this->actingAs(adminUser())
            ->post(route('admin.roles.store'), [
                'name' => 'teknisi',
                'permissions' => [Permission::WorkOrdersView->value, Permission::WorkOrdersUpdate->value],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.roles.index'));

        expect(Role::findByName('teknisi')->permissions->pluck('name')->sort()->values()->all())
            ->toBe([Permission::WorkOrdersUpdate->value, Permission::WorkOrdersView->value]);
    });

    it('rejects a duplicate name and unknown permissions', function () {
        $this->actingAs(adminUser())
            ->post(route('admin.roles.store'), ['name' => 'viewer', 'permissions' => ['everything.all']])
            ->assertSessionHasErrors(['name', 'permissions.0']);
    });
});

describe('update', function () {
    it('replaces the permissions of a role', function () {
        $admin = adminUser();
        $role = Role::findByName('viewer');

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $role), [
                'name' => 'pengamat',
                'permissions' => [Permission::UsersView->value],
            ])
            ->assertSessionHasNoErrors();

        expect($role->refresh())
            ->name->toBe('pengamat')
            ->and($role->permissions->pluck('name')->all())->toBe([Permission::UsersView->value]);
    });

    it('rejects renaming the admin role or reducing its permissions', function () {
        $admin = adminUser();
        $role = Role::findByName(SystemRole::Admin->value);

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $role), [
                'name' => 'superadmin',
                'permissions' => [Permission::UsersView->value],
            ])
            ->assertSessionHasErrors([
                'name' => 'Role admin tidak boleh diganti namanya.',
                'permissions' => 'Permission role admin tidak boleh dikurangi.',
            ]);

        expect($role->refresh()->permissions)->toHaveCount(count(Permission::cases()));
    });

    it('rejects removing role management from the only role that grants it to active users', function () {
        $admin = adminUser();
        $admin->update(['is_active' => false]);
        $supervisor = Role::create(['name' => 'supervisor', 'guard_name' => 'web'])
            ->givePermissionTo(Permission::RolesManage->value);
        $manager = User::factory()->create()->assignRole($supervisor);

        $this->actingAs($manager)
            ->put(route('admin.roles.update', $supervisor), ['name' => 'supervisor', 'permissions' => []])
            ->assertSessionHasErrors(['permissions' => 'Role ini satu-satunya sumber hak kelola role bagi pengguna aktif; permission roles.manage tidak boleh dicabut.']);

        expect($supervisor->refresh()->hasPermissionTo(Permission::RolesManage->value))->toBeTrue();
    });

    it('allows removing role management while another active user keeps it', function () {
        $admin = adminUser();
        $supervisor = Role::create(['name' => 'supervisor', 'guard_name' => 'web'])
            ->givePermissionTo(Permission::RolesManage->value);
        User::factory()->create()->assignRole($supervisor);

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $supervisor), ['name' => 'supervisor', 'permissions' => []])
            ->assertSessionHasNoErrors();

        expect($supervisor->refresh()->permissions)->toBeEmpty();
    });
});

describe('destroy', function () {
    it('deletes a role that no user holds', function () {
        $admin = adminUser();

        $this->actingAs($admin)
            ->delete(route('admin.roles.destroy', Role::findByName('viewer')))
            ->assertRedirect(route('admin.roles.index'));

        expect(Role::where('name', 'viewer')->exists())->toBeFalse();
    });

    it('refuses to delete the admin role', function () {
        $admin = adminUser();

        $this->actingAs($admin)
            ->delete(route('admin.roles.destroy', Role::findByName(SystemRole::Admin->value)))
            ->assertInertiaFlash('toast.message', 'Role admin tidak boleh dihapus.');

        expect(Role::where('name', SystemRole::Admin->value)->exists())->toBeTrue();
    });

    it('refuses to delete a role held by a user, even a soft-deleted one', function () {
        $admin = adminUser();
        User::factory()->create()->assignRole('viewer')->delete();

        $this->actingAs($admin)
            ->delete(route('admin.roles.destroy', Role::findByName('viewer')))
            ->assertInertiaFlash('toast.type', 'error');

        expect(Role::where('name', 'viewer')->exists())->toBeTrue();
    });
});

describe('authorization', function () {
    it('forbids users without the role management permission', function (string $method, Closure $url) {
        $user = userWithPermissions(...array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => $permission !== Permission::RolesManage,
        ));
        $role = Role::findByName('viewer');

        $this->actingAs($user)
            ->{$method}($url($role), ['name' => 'x', 'permissions' => []])
            ->assertForbidden();

        expect(Role::where('name', 'viewer')->exists())->toBeTrue();
    })->with([
        'index' => ['get', fn (): string => route('admin.roles.index')],
        'create' => ['get', fn (): string => route('admin.roles.create')],
        'store' => ['post', fn (): string => route('admin.roles.store')],
        'edit' => ['get', fn (Role $role): string => route('admin.roles.edit', $role)],
        'update' => ['put', fn (Role $role): string => route('admin.roles.update', $role)],
        'destroy' => ['delete', fn (Role $role): string => route('admin.roles.destroy', $role)],
    ]);
});
