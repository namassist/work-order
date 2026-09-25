<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

describe('index', function () {
    it('lists departments with search and status filter', function () {
        Department::factory()->create(['code' => 'FIN', 'name' => 'Keuangan']);
        Department::factory()->inactive()->create(['code' => 'OPS', 'name' => 'Operasional']);
        Department::factory()->create(['code' => 'HRD', 'name' => 'Sumber Daya Manusia']);

        $this->actingAs(userWithPermissions(Permission::DepartmentsView))
            ->get(route('admin.departments.index', ['search' => 'o', 'status' => 'inactive']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->component('admin/departments/Index')
                ->has('departments.data', 1)
                ->where('departments.data.0.code', 'OPS')
                ->where('departments.data.0.is_active', false));
    });

    it('searches code and name case-insensitively', function () {
        Department::factory()->create(['code' => 'FIN', 'name' => 'Keuangan']);

        $this->actingAs(userWithPermissions(Permission::DepartmentsView))
            ->get(route('admin.departments.index', ['search' => 'fin']))
            ->assertInertia(fn (Assert $page): Assert => $page->has('departments.data', 1));
    });

    it('hides soft-deleted departments from the default list', function () {
        Department::factory()->create(['code' => 'FIN']);
        Department::factory()->create(['code' => 'OLD'])->delete();

        $this->actingAs(userWithPermissions(Permission::DepartmentsView))
            ->get(route('admin.departments.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('departments.data', 1)
                ->where('departments.data.0.code', 'FIN'));
    });

    it('shows only soft-deleted departments when a restorer asks for them', function () {
        Department::factory()->create(['code' => 'FIN']);
        Department::factory()->create(['code' => 'OLD'])->delete();

        $this->actingAs(userWithPermissions(Permission::DepartmentsView, Permission::DepartmentsRestore))
            ->get(route('admin.departments.index', ['trashed' => 1]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('departments.data', 1)
                ->where('departments.data.0.code', 'OLD'));
    });

    it('forbids the deleted list without the restore permission', function () {
        $this->actingAs(userWithPermissions(Permission::DepartmentsView))
            ->get(route('admin.departments.index', ['trashed' => 1]))
            ->assertForbidden();
    });

    it('paginates 15 departments per page', function () {
        Department::factory()->count(16)->create();

        $this->actingAs(userWithPermissions(Permission::DepartmentsView))
            ->get(route('admin.departments.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('departments.data', 1)
                ->where('departments.total', 16));
    });
});

describe('store', function () {
    it('creates a department with an upper-cased code', function () {
        $this->actingAs(userWithPermissions(Permission::DepartmentsCreate))
            ->post(route('admin.departments.store'), ['code' => 'fin', 'name' => 'Keuangan', 'is_active' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.departments.index'));

        $this->assertDatabaseHas('departments', ['code' => 'FIN', 'name' => 'Keuangan', 'is_active' => true]);
    });

    it('rejects an empty payload', function () {
        $this->actingAs(userWithPermissions(Permission::DepartmentsCreate))
            ->post(route('admin.departments.store'), [])
            ->assertSessionHasErrors(['code', 'name', 'is_active']);
    });

    it('rejects a code used by an existing department', function () {
        Department::factory()->create(['code' => 'FIN']);

        $this->actingAs(userWithPermissions(Permission::DepartmentsCreate))
            ->post(route('admin.departments.store'), ['code' => 'FIN', 'name' => 'Lain', 'is_active' => true])
            ->assertSessionHasErrors(['code' => 'Kode sudah ada sebelumnya.']);
    });

    it('offers to restore when the code belongs to a deleted department', function () {
        Department::factory()->create(['code' => 'FIN'])->delete();

        $this->actingAs(userWithPermissions(Permission::DepartmentsCreate, Permission::DepartmentsRestore))
            ->post(route('admin.departments.store'), ['code' => 'FIN', 'name' => 'Lain', 'is_active' => true])
            ->assertSessionHasErrors(['code' => 'Kode ini dipakai oleh data yang sudah dihapus. Pulihkan data tersebut lewat filter "Tampilkan terhapus".']);

        expect(Department::withTrashed()->count())->toBe(1);
    });

    it('does not reveal deleted departments to users who cannot restore them', function () {
        Department::factory()->create(['code' => 'FIN'])->delete();

        $this->actingAs(userWithPermissions(Permission::DepartmentsCreate))
            ->post(route('admin.departments.store'), ['code' => 'FIN', 'name' => 'Lain', 'is_active' => true])
            ->assertSessionHasErrors(['code' => 'Kode sudah ada sebelumnya.']);
    });
});

describe('update', function () {
    it('updates and deactivates a department', function () {
        $department = Department::factory()->create(['code' => 'FIN']);

        $this->actingAs(userWithPermissions(Permission::DepartmentsUpdate))
            ->put(route('admin.departments.update', $department), ['code' => 'FIN', 'name' => 'Finance', 'is_active' => false])
            ->assertSessionHasNoErrors();

        expect($department->refresh())
            ->name->toBe('Finance')
            ->is_active->toBeFalse();
    });
});

describe('destroy', function () {
    it('soft-deletes a department without users', function () {
        $department = Department::factory()->create();

        $this->actingAs(userWithPermissions(Permission::DepartmentsDelete))
            ->delete(route('admin.departments.destroy', $department))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertSoftDeleted($department);
    });

    it('refuses to delete a department that still has users', function () {
        $department = Department::factory()->create();
        User::factory()->for($department)->inactive()->create();

        $this->actingAs(userWithPermissions(Permission::DepartmentsDelete))
            ->delete(route('admin.departments.destroy', $department))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertNotSoftDeleted($department);
    });

    it('deletes a department whose only users are soft-deleted', function () {
        $department = Department::factory()->create();
        User::factory()->for($department)->create()->delete();

        $this->actingAs(userWithPermissions(Permission::DepartmentsDelete))
            ->delete(route('admin.departments.destroy', $department));

        $this->assertSoftDeleted($department);
    });
});

describe('restore', function () {
    it('restores a soft-deleted department', function () {
        $department = Department::factory()->create();
        $department->delete();

        $this->actingAs(userWithPermissions(Permission::DepartmentsRestore))
            ->patch(route('admin.departments.restore', $department))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertNotSoftDeleted($department);
    });
});

describe('authorization', function () {
    it('redirects guests to login', function () {
        $this->get(route('admin.departments.index'))->assertRedirect(route('login'));
    });

    it('forbids users without the matching permission', function (string $method, Closure $url, array $payload) {
        $department = Department::factory()->create(['code' => 'FIN']);
        $deleted = Department::factory()->create(['code' => 'OLD']);
        $deleted->delete();

        $this->actingAs(userWithPermissions(Permission::WorkOrdersView))
            ->{$method}($url($department, $deleted), $payload)
            ->assertForbidden();

        expect(Department::count())->toBe(1)
            ->and(Department::onlyTrashed()->count())->toBe(1)
            ->and($department->refresh()->name)->not->toBe('X');
    })->with([
        'index' => ['get', fn (): string => route('admin.departments.index'), []],
        'store' => ['post', fn (): string => route('admin.departments.store'), ['code' => 'NEW', 'name' => 'Baru', 'is_active' => true]],
        'update' => ['put', fn (Department $department): string => route('admin.departments.update', $department), ['code' => 'FIN', 'name' => 'X', 'is_active' => true]],
        'destroy' => ['delete', fn (Department $department): string => route('admin.departments.destroy', $department), []],
        'restore' => ['patch', fn (Department $department, Department $deleted): string => route('admin.departments.restore', $deleted), []],
    ]);
});
