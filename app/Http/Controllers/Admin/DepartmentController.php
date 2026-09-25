<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDepartmentRequest;
use App\Http\Requests\Admin\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    /**
     * List departments with search, status filter, and pagination.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Department::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'trashed' => ['nullable', 'boolean'],
        ]);

        $showTrashed = (bool) ($filters['trashed'] ?? false);

        if ($showTrashed) {
            Gate::authorize('viewTrashed', Department::class);
        }

        $departments = Department::query()
            ->withCount('users')
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->when($showTrashed, fn ($query) => $query->onlyTrashed())
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        $user = $request->user();

        return Inertia::render('admin/departments/Index', [
            'departments' => $departments,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'trashed' => $showTrashed,
            ],
            'can' => [
                'create' => $user?->can('create', Department::class) ?? false,
                'update' => $user?->can('update', new Department) ?? false,
                'delete' => $user?->can('delete', new Department) ?? false,
                'restore' => $user?->can('viewTrashed', Department::class) ?? false,
            ],
        ]);
    }

    /**
     * Store a new department.
     */
    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        $department = Department::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Departemen :code dibuat.', ['code' => $department->code])]);

        return to_route('admin.departments.index');
    }

    /**
     * Update the department.
     */
    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Departemen :code diperbarui.', ['code' => $department->code])]);

        return back();
    }

    /**
     * Soft-delete the department, unless users are still assigned to it.
     */
    public function destroy(Department $department): RedirectResponse
    {
        Gate::authorize('delete', $department);

        if ($department->users()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Departemen :code masih memiliki pengguna. Pindahkan penggunanya atau nonaktifkan departemen ini.', ['code' => $department->code])]);

            return back();
        }

        $department->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Departemen :code dihapus.', ['code' => $department->code])]);

        return back();
    }

    /**
     * Restore a soft-deleted department.
     */
    public function restore(Department $department): RedirectResponse
    {
        Gate::authorize('restore', $department);

        $department->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Departemen :code dipulihkan.', ['code' => $department->code])]);

        return back();
    }
}
