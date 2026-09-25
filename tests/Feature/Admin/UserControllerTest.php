<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

describe('index', function () {
    it('filters users by department, role, and status', function () {
        $finance = Department::factory()->create();
        $admin = adminUser();
        User::factory()->for($finance)->inactive()->create(['name' => 'Budi'])->assignRole('keuangan');
        User::factory()->for($finance)->create(['name' => 'Citra'])->assignRole('keuangan');
        User::factory()->for($finance)->inactive()->create(['name' => 'Dewi'])->assignRole('viewer');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['department' => $finance->id, 'role' => 'keuangan', 'status' => 'inactive']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->component('admin/users/Index')
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Budi')
                ->where('users.data.0.roles', ['keuangan']));
    });

    it('searches users by name or email', function () {
        User::factory()->create(['name' => 'Budi', 'email' => 'budi@example.com']);
        User::factory()->create(['name' => 'Citra', 'email' => 'citra@example.com']);

        $this->actingAs(userWithPermissions(Permission::UsersView))
            ->get(route('admin.users.index', ['search' => 'citra@']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Citra'));
    });

    it('searches case-insensitively', function () {
        User::factory()->create(['name' => 'Budi Santoso']);

        $this->actingAs(userWithPermissions(Permission::UsersView))
            ->get(route('admin.users.index', ['search' => 'budi']))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Budi Santoso'));
    });

    it('treats LIKE wildcards in the search term as literal characters', function (string $term) {
        User::factory()->create(['name' => 'Budi']);

        $this->actingAs(userWithPermissions(Permission::UsersView))
            ->get(route('admin.users.index', ['search' => $term]))
            ->assertInertia(fn (Assert $page): Assert => $page->has('users.data', 0));
    })->with(['percent' => '%', 'underscore' => '_']);

    it('hides soft-deleted users from the default list but shows them on request', function () {
        $viewer = userWithPermissions(Permission::UsersView, Permission::UsersRestore);
        User::factory()->create(['name' => 'Zaki'])->delete();

        $this->actingAs($viewer)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page->has('users.data', 1));

        $this->actingAs($viewer)
            ->get(route('admin.users.index', ['trashed' => 1]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Zaki'));
    });

    it('forbids the deleted list without the restore permission', function () {
        $this->actingAs(userWithPermissions(Permission::UsersView))
            ->get(route('admin.users.index', ['trashed' => 1]))
            ->assertForbidden();
    });

    it('leaves soft-deleted departments out of the filter options', function () {
        Department::factory()->create(['code' => 'FIN']);
        Department::factory()->create(['code' => 'OLD'])->delete();

        $this->actingAs(userWithPermissions(Permission::UsersView))
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('departments', 1)
                ->where('departments.0.code', 'FIN'));
    });
});

describe('store', function () {
    it('creates a user with roles and the default password without sending email', function () {
        Notification::fake();
        config(['auth.default_user_password' => 'Rahasia#2026']);
        $department = Department::factory()->create();

        $this->actingAs(adminUser())
            ->post(route('admin.users.store'), [
                'name' => 'Budi',
                'email' => 'budi@example.com',
                'department_id' => $department->id,
                'roles' => ['pemohon', 'approver'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'budi@example.com')->firstOrFail();
        expect($user)
            ->department_id->toBe($department->id)
            ->is_active->toBeTrue()
            ->must_change_password->toBeTrue()
            ->and(Hash::check('Rahasia#2026', $user->password))->toBeTrue()
            ->and($user->getRoleNames()->sort()->values()->all())->toBe(['approver', 'pemohon']);
        Notification::assertNothingSent();
    });

    it('refuses to create a user when no default password is configured', function () {
        config(['auth.default_user_password' => null]);

        $this->withoutExceptionHandling()
            ->actingAs(adminUser())
            ->post(route('admin.users.store'), [
                'name' => 'Budi',
                'email' => 'budi@example.com',
                'department_id' => Department::factory()->create()->id,
            ]);
    })->throws(RuntimeException::class, 'DEFAULT_USER_PASSWORD');

    it('rejects an empty payload', function () {
        $this->actingAs(adminUser())
            ->post(route('admin.users.store'), [])
            ->assertSessionHasErrors(['name', 'email', 'department_id']);
    });

    it('rejects inactive or deleted departments', function (Department $department) {
        $this->actingAs(adminUser())
            ->post(route('admin.users.store'), [
                'name' => 'Budi',
                'email' => 'budi@example.com',
                'department_id' => $department->id,
            ])
            ->assertSessionHasErrors('department_id');
    })->with([
        'inactive' => fn () => Department::factory()->inactive()->create(),
        'deleted' => fn () => tap(Department::factory()->create())->delete(),
    ]);

    it('rejects an unknown role', function () {
        $this->actingAs(adminUser())
            ->post(route('admin.users.store'), [
                'name' => 'Budi',
                'email' => 'budi@example.com',
                'department_id' => Department::factory()->create()->id,
                'roles' => ['superuser'],
            ])
            ->assertSessionHasErrors('roles.0');
    });

    it('offers to restore when the email belongs to a deleted user', function () {
        User::factory()->create(['email' => 'budi@example.com'])->delete();

        $this->actingAs(adminUser())
            ->post(route('admin.users.store'), [
                'name' => 'Budi',
                'email' => 'budi@example.com',
                'department_id' => Department::factory()->create()->id,
            ])
            ->assertSessionHasErrors(['email' => 'Email ini dipakai oleh data yang sudah dihapus. Pulihkan data tersebut lewat filter "Tampilkan terhapus".']);
    });

    it('forbids assigning roles without the role management permission', function () {
        $this->actingAs(userWithPermissions(Permission::UsersCreate))
            ->post(route('admin.users.store'), [
                'name' => 'Budi',
                'email' => 'budi@example.com',
                'department_id' => Department::factory()->create()->id,
                'roles' => [SystemRole::Admin->value],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'budi@example.com']);
    });
});

describe('update', function () {
    it('updates the profile, department, status, and roles', function () {
        $admin = adminUser();
        $department = Department::factory()->create();
        $user = User::factory()->create()->assignRole('viewer');

        $this->actingAs($admin)
            ->put(route('admin.users.update', $user), [
                'name' => 'Nama Baru',
                'email' => $user->email,
                'department_id' => $department->id,
                'is_active' => false,
                'roles' => ['keuangan'],
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();
        expect($user)
            ->name->toBe('Nama Baru')
            ->department_id->toBe($department->id)
            ->is_active->toBeFalse()
            ->and($user->getRoleNames()->all())->toBe(['keuangan']);
    });

    it('lets a user keep a department that was deactivated later', function () {
        $department = Department::factory()->inactive()->create();
        $user = User::factory()->for($department)->create();

        $this->actingAs(userWithPermissions(Permission::UsersUpdate))
            ->put(route('admin.users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'department_id' => $department->id,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();
    });

    it('forbids changing roles without the role management permission', function () {
        $user = User::factory()->for(Department::factory())->create();

        $this->actingAs(userWithPermissions(Permission::UsersUpdate))
            ->put(route('admin.users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'department_id' => $user->department_id,
                'is_active' => true,
                'roles' => [SystemRole::Admin->value],
            ])
            ->assertForbidden();

        expect($user->roles()->count())->toBe(0);
    });

    it('rejects deactivating yourself', function () {
        $admin = adminUser();
        $admin->update(['department_id' => Department::factory()->create()->id]);
        adminUser();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'department_id' => $admin->department_id,
                'is_active' => false,
            ])
            ->assertSessionHasErrors(['is_active' => 'Anda tidak dapat menonaktifkan akun sendiri.']);
    });

    it('rejects deactivating the last role manager', function () {
        $lastAdmin = adminUser();
        $lastAdmin->update(['department_id' => Department::factory()->create()->id]);

        $this->actingAs(userWithPermissions(Permission::UsersUpdate))
            ->put(route('admin.users.update', $lastAdmin), [
                'name' => $lastAdmin->name,
                'email' => $lastAdmin->email,
                'department_id' => $lastAdmin->department_id,
                'is_active' => false,
            ])
            ->assertSessionHasErrors(['is_active' => 'Pengguna ini satu-satunya pengelola role yang aktif dan tidak boleh dinonaktifkan.']);

        expect($lastAdmin->refresh()->is_active)->toBeTrue();
    });

    it('rejects the last role manager removing their own admin role', function () {
        $lastAdmin = adminUser();
        $lastAdmin->update(['department_id' => Department::factory()->create()->id]);

        $this->actingAs($lastAdmin)
            ->put(route('admin.users.update', $lastAdmin), [
                'name' => $lastAdmin->name,
                'email' => $lastAdmin->email,
                'department_id' => $lastAdmin->department_id,
                'is_active' => true,
                'roles' => ['viewer'],
            ])
            ->assertSessionHasErrors(['roles' => 'Pengguna ini satu-satunya pengelola role yang aktif; hak kelola role tidak boleh dicabut.']);

        expect($lastAdmin->refresh()->hasRole(SystemRole::Admin->value))->toBeTrue();
    });
});

describe('destroy', function () {
    it('soft-deletes a user and keeps their role assignments', function () {
        $admin = adminUser();
        $user = User::factory()->create()->assignRole('pemohon');

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $user))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertSoftDeleted($user);
        $this->assertDatabaseHas('model_has_roles', ['model_id' => $user->id, 'model_type' => $user->getMorphClass()]);
    });

    it('forbids deleting yourself', function () {
        $admin = adminUser();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertForbidden();

        $this->assertNotSoftDeleted($admin);
    });

    it('refuses to delete the last role manager', function () {
        $lastAdmin = adminUser();

        $this->actingAs(userWithPermissions(Permission::UsersDelete))
            ->delete(route('admin.users.destroy', $lastAdmin))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertNotSoftDeleted($lastAdmin);
    });
});

describe('restore', function () {
    it('restores a soft-deleted user', function () {
        $user = User::factory()->create();
        $user->delete();

        $this->actingAs(userWithPermissions(Permission::UsersRestore))
            ->patch(route('admin.users.restore', $user))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertNotSoftDeleted($user);
    });
});

describe('authorization', function () {
    it('forbids users without the matching permission', function (string $method, Closure $url) {
        $user = User::factory()->create();
        $deleted = User::factory()->create();
        $deleted->delete();

        $this->actingAs(userWithPermissions(Permission::WorkOrdersView))
            ->{$method}($url($user, $deleted), [])
            ->assertForbidden();

        $this->assertNotSoftDeleted($user);
        $this->assertSoftDeleted($deleted);
    })->with([
        'index' => ['get', fn (): string => route('admin.users.index')],
        'create' => ['get', fn (): string => route('admin.users.create')],
        'store' => ['post', fn (): string => route('admin.users.store')],
        'edit' => ['get', fn (User $user): string => route('admin.users.edit', $user)],
        'update' => ['put', fn (User $user): string => route('admin.users.update', $user)],
        'destroy' => ['delete', fn (User $user): string => route('admin.users.destroy', $user)],
        'restore' => ['patch', fn (User $user, User $deleted): string => route('admin.users.restore', $deleted)],
    ]);
});
