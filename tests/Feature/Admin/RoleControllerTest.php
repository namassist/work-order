<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Department;
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
                ->has('roles', 6)
                ->where('roles.0.name', 'admin')
                ->where('roles.0.company_scope', ['value' => 'executor', 'label' => 'Perusahaan pelaksana'])
                ->where('roles.5.name', 'viewer')
                ->where('roles.5.company_scope', null)
                ->where('roles.5.users_count', 2));
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
                'company_scope' => 'executor',
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

describe('company scope', function () {
    it('stores the company scope of a new role', function () {
        $this->actingAs(adminUser())
            ->post(route('admin.roles.store'), [
                'name' => 'teknisi',
                'company_scope' => 'executor',
                'permissions' => [Permission::WorkOrdersView->value],
            ])
            ->assertSessionHasNoErrors();

        expect(Role::findByName('teknisi')->company_scope)->toBe('executor');
    });

    it('refuses internal-only permissions on a role that is not for the executor company', function (?string $scope, Permission $permission) {
        $this->actingAs(adminUser())
            ->post(route('admin.roles.store'), [
                'name' => 'campuran',
                'company_scope' => $scope,
                'permissions' => [Permission::WorkOrdersView->value, $permission->value],
            ])
            ->assertSessionHasErrors(['permissions' => "Izin internal hanya untuk role perusahaan pelaksana: {$permission->value}."]);

        expect(Role::where('name', 'campuran')->exists())->toBeFalse();
    })->with(['client' => 'client', 'any company' => null])
        ->with(fn (): array => array_map(fn (Permission $permission): array => [$permission], array_values(array_filter(Permission::cases(), fn (Permission $permission): bool => $permission->isInternalOnly()))));

    it('allows internal-only permissions on an executor role', function () {
        $this->actingAs(adminUser())
            ->post(route('admin.roles.store'), [
                'name' => 'viewer-internal',
                'company_scope' => 'executor',
                'permissions' => [Permission::WorkOrdersView->value, Permission::DepartmentsView->value, Permission::ActivityLogView->value],
            ])
            ->assertSessionHasNoErrors();

        expect(Role::findByName('viewer-internal')->permissions)->toHaveCount(3);
    });

    it('refuses widening an executor role that holds internal-only permissions', function () {
        $admin = adminUser();
        $role = Role::create(['name' => 'auditor', 'guard_name' => 'web', 'company_scope' => 'executor'])
            ->givePermissionTo(Permission::ActivityLogView->value);

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $role), ['name' => 'auditor', 'company_scope' => null, 'permissions' => [Permission::ActivityLogView->value]])
            ->assertSessionHasErrors('permissions');

        expect($role->refresh()->company_scope)->toBe('executor');
    });

    it('keeps the admin role for the executor company', function (?string $scope) {
        $admin = adminUser();
        $role = Role::findByName(SystemRole::Admin->value);

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $role), [
                'name' => SystemRole::Admin->value,
                'company_scope' => $scope,
                'permissions' => Permission::values(),
            ])
            ->assertSessionHasErrors(['company_scope' => 'Role admin hanya untuk perusahaan pelaksana.']);
    })->with(['client', null]);

    it('refuses a scope that excludes users who already hold the role, deleted ones included', function (bool $deleted) {
        $admin = adminUser();
        $role = Role::findByName('viewer');
        $holder = User::factory()->for(Department::factory()->client())->create()->assignRole('viewer');

        if ($deleted) {
            $holder->delete();
        }

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $role), ['name' => 'viewer', 'company_scope' => 'executor', 'permissions' => []])
            ->assertSessionHasErrors(['company_scope' => '1 pengguna dengan role ini bukan dari perusahaan pelaksana. Ubah role mereka terlebih dahulu.']);

        expect($role->refresh()->company_scope)->toBeNull();
    })->with(['active holder' => false, 'deleted holder' => true]);

    it('narrows the scope when every holder fits', function () {
        $admin = adminUser();
        $role = Role::findByName('viewer');
        User::factory()->for(Department::factory()->client())->create()->assignRole('viewer');

        $this->actingAs($admin)
            ->put(route('admin.roles.update', $role), ['name' => 'viewer', 'company_scope' => 'client', 'permissions' => [Permission::WorkOrdersView->value]])
            ->assertSessionHasNoErrors();

        expect($role->refresh()->company_scope)->toBe('client');
    });

    it('gives the form the scopes and the internal-only permissions', function () {
        $this->actingAs(adminUser())
            ->get(route('admin.roles.create'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('companyScopes', [
                    ['value' => 'client', 'label' => 'Perusahaan klien'],
                    ['value' => 'executor', 'label' => 'Perusahaan pelaksana'],
                ])
                ->where('internalOnlyPermissions', Permission::internalOnlyValues()));
    });
});
