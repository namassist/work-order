<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWorkOrderCategoryRequest;
use App\Http\Requests\Admin\UpdateWorkOrderCategoryRequest;
use App\Models\WorkOrderCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderCategoryController extends Controller
{
    /**
     * List work order categories with search, status filter, and pagination.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrderCategory::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'trashed' => ['nullable', 'boolean'],
        ]);

        $showTrashed = (bool) ($filters['trashed'] ?? false);

        if ($showTrashed) {
            Gate::authorize('viewTrashed', WorkOrderCategory::class);
        }

        $categories = WorkOrderCategory::query()
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->when($showTrashed, fn ($query) => $query->onlyTrashed())
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        $user = $request->user();

        return Inertia::render('admin/work-order-categories/Index', [
            'categories' => $categories,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'trashed' => $showTrashed,
            ],
            'can' => [
                'create' => $user?->can('create', WorkOrderCategory::class) ?? false,
                'update' => $user?->can('update', new WorkOrderCategory) ?? false,
                'delete' => $user?->can('delete', new WorkOrderCategory) ?? false,
                'restore' => $user?->can('viewTrashed', WorkOrderCategory::class) ?? false,
            ],
        ]);
    }

    /**
     * Store a new work order category.
     */
    public function store(StoreWorkOrderCategoryRequest $request): RedirectResponse
    {
        $category = WorkOrderCategory::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Kategori WO :code dibuat.', ['code' => $category->code])]);

        return to_route('admin.work-order-categories.index');
    }

    /**
     * Update the work order category.
     */
    public function update(UpdateWorkOrderCategoryRequest $request, WorkOrderCategory $category): RedirectResponse
    {
        $category->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Kategori WO :code diperbarui.', ['code' => $category->code])]);

        return back();
    }

    /**
     * Soft-delete the work order category. Allowed even while work orders use
     * it: they reference categories withTrashed(), and deleting only removes
     * the category from new forms.
     */
    public function destroy(WorkOrderCategory $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $category->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Kategori WO :code dihapus.', ['code' => $category->code])]);

        return back();
    }

    /**
     * Restore a soft-deleted work order category.
     */
    public function restore(WorkOrderCategory $category): RedirectResponse
    {
        Gate::authorize('restore', $category);

        $category->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Kategori WO :code dipulihkan.', ['code' => $category->code])]);

        return back();
    }
}
