<?php

use App\Enums\Permission;
use App\Models\WorkOrderCategory;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

describe('index', function () {
    it('lists categories with search and status filter', function () {
        WorkOrderCategory::factory()->create(['code' => 'LST', 'name' => 'Listrik']);
        WorkOrderCategory::factory()->inactive()->create(['code' => 'BGN', 'name' => 'Bangunan']);
        WorkOrderCategory::factory()->create(['code' => 'KND', 'name' => 'Kendaraan']);

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesView))
            ->get(route('admin.work-order-categories.index', ['search' => 'an', 'status' => 'inactive']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->component('admin/work-order-categories/Index')
                ->has('categories.data', 1)
                ->where('categories.data.0.code', 'BGN')
                ->where('categories.data.0.is_active', false));
    });

    it('searches code and name case-insensitively', function () {
        WorkOrderCategory::factory()->create(['code' => 'LST', 'name' => 'Listrik']);

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesView))
            ->get(route('admin.work-order-categories.index', ['search' => 'lst']))
            ->assertInertia(fn (Assert $page): Assert => $page->has('categories.data', 1));
    });

    it('hides soft-deleted categories from the default list', function () {
        WorkOrderCategory::factory()->create(['code' => 'LST']);
        WorkOrderCategory::factory()->create(['code' => 'OLD'])->delete();

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesView))
            ->get(route('admin.work-order-categories.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('categories.data', 1)
                ->where('categories.data.0.code', 'LST'));
    });

    it('shows only soft-deleted categories when a restorer asks for them', function () {
        WorkOrderCategory::factory()->create(['code' => 'LST']);
        WorkOrderCategory::factory()->create(['code' => 'OLD'])->delete();

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesView, Permission::WorkOrderCategoriesRestore))
            ->get(route('admin.work-order-categories.index', ['trashed' => 1]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('categories.data', 1)
                ->where('categories.data.0.code', 'OLD'));
    });

    it('forbids the deleted list without the restore permission', function () {
        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesView))
            ->get(route('admin.work-order-categories.index', ['trashed' => 1]))
            ->assertForbidden();
    });

    it('paginates 15 categories per page', function () {
        WorkOrderCategory::factory()->count(16)->create();

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesView))
            ->get(route('admin.work-order-categories.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('categories.data', 1)
                ->where('categories.total', 16));
    });
});

describe('store', function () {
    it('creates a category with an upper-cased code', function () {
        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesCreate))
            ->post(route('admin.work-order-categories.store'), [
                'code' => 'lst',
                'name' => 'Listrik',
                'description' => 'Instalasi dan perbaikan listrik',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.work-order-categories.index'));

        $this->assertDatabaseHas('work_order_categories', [
            'code' => 'LST',
            'name' => 'Listrik',
            'description' => 'Instalasi dan perbaikan listrik',
            'is_active' => true,
        ]);
    });

    it('allows an empty description', function () {
        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesCreate))
            ->post(route('admin.work-order-categories.store'), ['code' => 'LST', 'name' => 'Listrik', 'description' => '', 'is_active' => true])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_categories', ['code' => 'LST', 'description' => null]);
    });

    it('rejects an empty payload', function () {
        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesCreate))
            ->post(route('admin.work-order-categories.store'), [])
            ->assertSessionHasErrors(['code', 'name', 'is_active']);
    });

    it('rejects a code with invalid characters and an overlong description', function () {
        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesCreate))
            ->post(route('admin.work-order-categories.store'), [
                'code' => 'LS T',
                'name' => 'Listrik',
                'description' => str_repeat('a', 1001),
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['code', 'description']);
    });

    it('rejects a code used by an existing category', function () {
        WorkOrderCategory::factory()->create(['code' => 'LST']);

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesCreate))
            ->post(route('admin.work-order-categories.store'), ['code' => 'LST', 'name' => 'Lain', 'is_active' => true])
            ->assertSessionHasErrors(['code' => 'The code has already been taken.']);
    });

    it('offers to restore when the code belongs to a deleted category', function () {
        WorkOrderCategory::factory()->create(['code' => 'LST'])->delete();

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesCreate, Permission::WorkOrderCategoriesRestore))
            ->post(route('admin.work-order-categories.store'), ['code' => 'LST', 'name' => 'Lain', 'is_active' => true])
            ->assertSessionHasErrors(['code' => 'Code ini dipakai oleh data yang sudah dihapus. Pulihkan data tersebut lewat filter "Tampilkan terhapus".']);

        expect(WorkOrderCategory::withTrashed()->count())->toBe(1);
    });

    it('does not reveal deleted categories to users who cannot restore them', function () {
        WorkOrderCategory::factory()->create(['code' => 'LST'])->delete();

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesCreate))
            ->post(route('admin.work-order-categories.store'), ['code' => 'LST', 'name' => 'Lain', 'is_active' => true])
            ->assertSessionHasErrors(['code' => 'The code has already been taken.']);
    });
});

describe('update', function () {
    it('updates and deactivates a category while keeping its code', function () {
        $category = WorkOrderCategory::factory()->create(['code' => 'LST']);

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesUpdate))
            ->put(route('admin.work-order-categories.update', $category), [
                'code' => 'LST',
                'name' => 'Kelistrikan',
                'description' => 'Panel dan kabel',
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors();

        expect($category->refresh())
            ->name->toBe('Kelistrikan')
            ->description->toBe('Panel dan kabel')
            ->is_active->toBeFalse();
    });

    it('rejects the code of another category', function () {
        $category = WorkOrderCategory::factory()->create(['code' => 'LST']);
        WorkOrderCategory::factory()->create(['code' => 'BGN']);

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesUpdate))
            ->put(route('admin.work-order-categories.update', $category), ['code' => 'BGN', 'name' => 'X', 'is_active' => true])
            ->assertSessionHasErrors(['code' => 'The code has already been taken.']);
    });
});

describe('destroy', function () {
    it('soft-deletes a category', function () {
        $category = WorkOrderCategory::factory()->create();

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesDelete))
            ->delete(route('admin.work-order-categories.destroy', $category))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertSoftDeleted($category);
    });
});

describe('restore', function () {
    it('restores a soft-deleted category', function () {
        $category = WorkOrderCategory::factory()->create();
        $category->delete();

        $this->actingAs(userWithPermissions(Permission::WorkOrderCategoriesRestore))
            ->patch(route('admin.work-order-categories.restore', $category))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertNotSoftDeleted($category);
    });
});

describe('authorization', function () {
    it('redirects guests to login', function () {
        $this->get(route('admin.work-order-categories.index'))->assertRedirect(route('login'));
    });

    it('forbids users without the matching permission', function (string $method, Closure $url, array $payload) {
        $category = WorkOrderCategory::factory()->create(['code' => 'LST']);
        $deleted = WorkOrderCategory::factory()->create(['code' => 'OLD']);
        $deleted->delete();

        $this->actingAs(userWithPermissions(Permission::WorkOrdersView))
            ->{$method}($url($category, $deleted), $payload)
            ->assertForbidden();

        expect(WorkOrderCategory::count())->toBe(1)
            ->and(WorkOrderCategory::onlyTrashed()->count())->toBe(1)
            ->and($category->refresh()->name)->not->toBe('X');
    })->with([
        'index' => ['get', fn (): string => route('admin.work-order-categories.index'), []],
        'store' => ['post', fn (): string => route('admin.work-order-categories.store'), ['code' => 'NEW', 'name' => 'Baru', 'is_active' => true]],
        'update' => ['put', fn (WorkOrderCategory $category): string => route('admin.work-order-categories.update', $category), ['code' => 'LST', 'name' => 'X', 'is_active' => true]],
        'destroy' => ['delete', fn (WorkOrderCategory $category): string => route('admin.work-order-categories.destroy', $category), []],
        'restore' => ['patch', fn (WorkOrderCategory $category, WorkOrderCategory $deleted): string => route('admin.work-order-categories.restore', $deleted), []],
    ]);
});
