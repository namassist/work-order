<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    /**
     * List companies with search, filters, and pagination.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Company::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'type' => ['nullable', 'in:client,executor'],
            'trashed' => ['nullable', 'boolean'],
        ]);

        $showTrashed = (bool) ($filters['trashed'] ?? false);

        if ($showTrashed) {
            Gate::authorize('viewTrashed', Company::class);
        }

        $companies = Company::query()
            ->withCount('departments')
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('is_client', $type === 'client'))
            ->when($showTrashed, fn ($query) => $query->onlyTrashed())
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        $user = $request->user();

        return Inertia::render('admin/companies/Index', [
            'companies' => $companies,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'type' => $filters['type'] ?? '',
                'trashed' => $showTrashed,
            ],
            'can' => [
                'create' => $user?->can('create', Company::class) ?? false,
                'update' => $user?->can('update', new Company) ?? false,
                'delete' => $user?->can('delete', new Company) ?? false,
                'restore' => $user?->can('viewTrashed', Company::class) ?? false,
            ],
        ]);
    }

    /**
     * Store a new company.
     */
    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $company = Company::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Perusahaan :code dibuat.', ['code' => $company->code])]);

        return to_route('admin.companies.index');
    }

    /**
     * Update the company.
     */
    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        $company->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Perusahaan :code diperbarui.', ['code' => $company->code])]);

        return back();
    }

    /**
     * Soft-delete the company, unless it still has departments.
     */
    public function destroy(Company $company): RedirectResponse
    {
        Gate::authorize('delete', $company);

        if ($company->departments()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Perusahaan :code masih memiliki departemen. Pindahkan atau hapus departemennya, atau nonaktifkan perusahaan ini.', ['code' => $company->code])]);

            return back();
        }

        $company->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Perusahaan :code dihapus.', ['code' => $company->code])]);

        return back();
    }

    /**
     * Restore a soft-deleted company.
     */
    public function restore(Company $company): RedirectResponse
    {
        Gate::authorize('restore', $company);

        $company->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Perusahaan :code dipulihkan.', ['code' => $company->code])]);

        return back();
    }
}
