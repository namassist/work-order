<?php

use App\Enums\Permission;
use App\Models\Company;
use App\Models\Department;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A valid company payload with the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function companyPayload(array $overrides = []): array
{
    return [
        'code' => 'IC',
        'name' => 'IC',
        'is_client' => true,
        'email_domains' => ['ic.co.id'],
        'is_active' => true,
        ...$overrides,
    ];
}

describe('index', function () {
    it('lists companies with search, status, and type filters', function () {
        $viewer = userWithPermissions(Permission::CompaniesView);
        Company::factory()->client()->create(['code' => 'IC', 'name' => 'Indo Client']);
        Company::factory()->client()->inactive()->create(['code' => 'OLDC', 'name' => 'Old Client']);
        Company::factory()->create(['code' => 'UGL', 'name' => 'Unggul']);

        $this->actingAs($viewer)
            ->get(route('admin.companies.index', ['search' => 'client', 'status' => 'active', 'type' => 'client']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->component('admin/companies/Index')
                ->has('companies.data', 1)
                ->where('companies.data.0.code', 'IC')
                ->where('companies.data.0.is_client', true)
                ->where('companies.data.0.email_domains', fn ($domains): bool => count($domains) === 1)
                ->where('filters', ['search' => 'client', 'status' => 'active', 'type' => 'client', 'trashed' => false]));
    });

    it('counts each company\'s departments', function () {
        $viewer = userWithPermissions(Permission::CompaniesView);
        $company = Company::factory()->client()->create(['code' => 'IC']);
        Department::factory()->count(2)->for($company)->create();

        $this->actingAs($viewer)
            ->get(route('admin.companies.index', ['search' => 'IC', 'type' => 'client']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('companies.data.0.departments_count', 2));
    });

    it('hides soft-deleted companies from the default list', function () {
        $viewer = userWithPermissions(Permission::CompaniesView);
        Company::factory()->client()->create(['code' => 'OLD'])->delete();

        $this->actingAs($viewer)
            ->get(route('admin.companies.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('companies.data', fn ($companies): bool => ! collect($companies)->contains('code', 'OLD')));
    });

    it('shows only soft-deleted companies when a restorer asks for them', function () {
        $viewer = userWithPermissions(Permission::CompaniesView, Permission::CompaniesRestore);
        Company::factory()->create(['code' => 'OLD'])->delete();

        $this->actingAs($viewer)
            ->get(route('admin.companies.index', ['trashed' => 1]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('companies.data', 1)
                ->where('companies.data.0.code', 'OLD'));
    });

    it('forbids the deleted list without the restore permission', function () {
        $this->actingAs(userWithPermissions(Permission::CompaniesView))
            ->get(route('admin.companies.index', ['trashed' => 1]))
            ->assertForbidden();
    });

    it('paginates 15 companies per page', function () {
        // With the viewer's own company: 16.
        $viewer = userWithPermissions(Permission::CompaniesView);
        Company::factory()->count(15)->create();

        $this->actingAs($viewer)
            ->get(route('admin.companies.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('companies.data', 1)
                ->where('companies.total', 16));
    });
});

describe('store', function () {
    it('creates a company with an upper-cased code and normalized domains', function () {
        $this->actingAs(userWithPermissions(Permission::CompaniesCreate))
            ->post(route('admin.companies.store'), companyPayload([
                'code' => 'ic',
                'email_domains' => [' @IC.co.id ', 'mail.ic.co.id', ''],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.companies.index'));

        expect(Company::where('code', 'IC')->firstOrFail())
            ->name->toBe('IC')
            ->is_client->toBeTrue()
            ->email_domains->toBe(['ic.co.id', 'mail.ic.co.id'])
            ->is_active->toBeTrue();
    });

    it('accepts a company without email domains', function () {
        $this->actingAs(userWithPermissions(Permission::CompaniesCreate))
            ->post(route('admin.companies.store'), companyPayload(['email_domains' => []]))
            ->assertSessionHasNoErrors();
    });

    it('rejects an empty payload', function () {
        $this->actingAs(userWithPermissions(Permission::CompaniesCreate))
            ->post(route('admin.companies.store'), [])
            ->assertSessionHasErrors(['code', 'name', 'is_client', 'email_domains', 'is_active']);
    });

    it('rejects an invalid or repeated domain', function (array $domains, string $error) {
        $this->actingAs(userWithPermissions(Permission::CompaniesCreate))
            ->post(route('admin.companies.store'), companyPayload(['email_domains' => $domains]))
            ->assertSessionHasErrors($error);

        expect(Company::where('code', 'IC')->exists())->toBeFalse();
    })->with([
        'no dot' => [['localhost'], 'email_domains.0'],
        'an address' => [['budi@ic.co.id'], 'email_domains.0'],
        'a URL' => [['https://ic.co.id'], 'email_domains.0'],
        'twice' => [['ic.co.id', 'IC.co.id'], 'email_domains.1'],
    ]);

    it('rejects a domain another company uses, deleted companies included', function (bool $deleted) {
        $other = Company::factory()->create(['email_domains' => ['ic.co.id']]);

        if ($deleted) {
            $other->delete();
        }

        $this->actingAs(userWithPermissions(Permission::CompaniesCreate))
            ->post(route('admin.companies.store'), companyPayload())
            ->assertSessionHasErrors(['email_domains.0' => 'Domain ic.co.id sudah dipakai perusahaan lain.']);
    })->with(['active' => false, 'deleted' => true]);

    it('rejects a code used by an existing company', function () {
        Company::factory()->create(['code' => 'IC']);

        $this->actingAs(userWithPermissions(Permission::CompaniesCreate))
            ->post(route('admin.companies.store'), companyPayload())
            ->assertSessionHasErrors(['code' => 'Kode sudah ada sebelumnya.']);
    });

    it('offers to restore when the code belongs to a deleted company', function () {
        Company::factory()->create(['code' => 'IC'])->delete();

        $this->actingAs(userWithPermissions(Permission::CompaniesCreate, Permission::CompaniesRestore))
            ->post(route('admin.companies.store'), companyPayload())
            ->assertSessionHasErrors(['code' => 'Kode ini dipakai oleh data yang sudah dihapus. Pulihkan data tersebut lewat filter "Tampilkan terhapus".']);

        expect(Company::withTrashed()->where('code', 'IC')->count())->toBe(1);
    });

    it('does not reveal deleted companies to users who cannot restore them', function () {
        Company::factory()->create(['code' => 'IC'])->delete();

        $this->actingAs(userWithPermissions(Permission::CompaniesCreate))
            ->post(route('admin.companies.store'), companyPayload())
            ->assertSessionHasErrors(['code' => 'Kode sudah ada sebelumnya.']);
    });
});

describe('update', function () {
    it('updates and deactivates a company, keeping its own domains', function () {
        $company = Company::factory()->client()->create(['code' => 'IC', 'email_domains' => ['ic.co.id']]);

        $this->actingAs(userWithPermissions(Permission::CompaniesUpdate))
            ->put(route('admin.companies.update', $company), companyPayload([
                'name' => 'Indo Client',
                'email_domains' => ['ic.co.id', 'ic.com'],
                'is_active' => false,
            ]))
            ->assertSessionHasNoErrors();

        expect($company->refresh())
            ->name->toBe('Indo Client')
            ->email_domains->toBe(['ic.co.id', 'ic.com'])
            ->is_active->toBeFalse();
    });

    it('changes whether a company is a client while it has no departments', function () {
        $company = Company::factory()->create(['code' => 'IC']);

        $this->actingAs(userWithPermissions(Permission::CompaniesUpdate))
            ->put(route('admin.companies.update', $company), companyPayload(['is_client' => true]))
            ->assertSessionHasNoErrors();

        expect($company->refresh()->is_client)->toBeTrue();
    });

    it('refuses to change whether a company is a client once it has departments, deleted ones included', function (bool $deleted) {
        $company = Company::factory()->client()->create(['code' => 'IC']);
        $department = Department::factory()->for($company)->create();

        if ($deleted) {
            $department->delete();
        }

        $this->actingAs(userWithPermissions(Permission::CompaniesUpdate))
            ->put(route('admin.companies.update', $company), companyPayload(['is_client' => false]))
            ->assertSessionHasErrors(['is_client' => 'Jenis perusahaan tidak dapat diubah karena perusahaan ini sudah memiliki departemen.']);

        expect($company->refresh()->is_client)->toBeTrue();
    })->with(['active department' => false, 'deleted department' => true]);
});

describe('destroy', function () {
    it('soft-deletes a company without departments', function () {
        $company = Company::factory()->create();

        $this->actingAs(userWithPermissions(Permission::CompaniesDelete))
            ->delete(route('admin.companies.destroy', $company))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertSoftDeleted($company);
    });

    it('refuses to delete a company that still has departments', function () {
        $company = Company::factory()->create();
        Department::factory()->for($company)->inactive()->create();

        $this->actingAs(userWithPermissions(Permission::CompaniesDelete))
            ->delete(route('admin.companies.destroy', $company))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertNotSoftDeleted($company);
    });

    it('deletes a company whose only departments are soft-deleted', function () {
        $company = Company::factory()->create();
        Department::factory()->for($company)->create()->delete();

        $this->actingAs(userWithPermissions(Permission::CompaniesDelete))
            ->delete(route('admin.companies.destroy', $company));

        $this->assertSoftDeleted($company);
    });
});

describe('restore', function () {
    it('restores a soft-deleted company', function () {
        $company = Company::factory()->create();
        $company->delete();

        $this->actingAs(userWithPermissions(Permission::CompaniesRestore))
            ->patch(route('admin.companies.restore', $company))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertNotSoftDeleted($company);
    });
});

describe('authorization', function () {
    it('redirects guests to login', function () {
        $this->get(route('admin.companies.index'))->assertRedirect(route('login'));
    });

    it('forbids users without the matching permission', function (string $method, Closure $url, array $payload) {
        $company = Company::factory()->create(['code' => 'IC', 'name' => 'IC']);
        $deleted = Company::factory()->create(['code' => 'OLD']);
        $deleted->delete();

        $this->actingAs(userWithPermissions(Permission::WorkOrdersView))
            ->{$method}($url($company, $deleted), $payload)
            ->assertForbidden();

        expect(Company::whereIn('code', ['IC', 'NEW'])->count())->toBe(1)
            ->and(Company::onlyTrashed()->count())->toBe(1)
            ->and($company->refresh()->name)->toBe('IC');
    })->with([
        'index' => ['get', fn (): string => route('admin.companies.index'), []],
        'store' => ['post', fn (): string => route('admin.companies.store'), companyPayload(['code' => 'NEW', 'email_domains' => []])],
        'update' => ['put', fn (Company $company): string => route('admin.companies.update', $company), companyPayload(['name' => 'X', 'email_domains' => []])],
        'destroy' => ['delete', fn (Company $company): string => route('admin.companies.destroy', $company), []],
        'restore' => ['patch', fn (Company $company, Company $deleted): string => route('admin.companies.restore', $deleted), []],
    ]);
});
