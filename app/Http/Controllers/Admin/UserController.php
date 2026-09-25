<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * List users with search, filters, and pagination.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'integer'],
            'role' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:active,inactive'],
            'trashed' => ['nullable', 'boolean'],
        ]);

        $showTrashed = (bool) ($filters['trashed'] ?? false);

        if ($showTrashed) {
            Gate::authorize('viewTrashed', User::class);
        }

        $users = User::query()
            ->with(['department:id,code,name,deleted_at', 'roles:id,name'])
            ->search($filters['search'] ?? null)
            ->when($filters['department'] ?? null, fn ($query, int $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->whereRelation('roles', 'name', $role))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->when($showTrashed, fn ($query) => $query->onlyTrashed())
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'deleted_at' => $user->deleted_at?->toIso8601String(),
                'department' => $user->department?->only(['id', 'code', 'name']),
                'roles' => $user->roles->pluck('name')->all(),
            ]);

        $authUser = $request->user();

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'department' => isset($filters['department']) ? (string) $filters['department'] : '',
                'role' => $filters['role'] ?? '',
                'status' => $filters['status'] ?? '',
                'trashed' => $showTrashed,
            ],
            'departments' => Department::orderBy('code')->get(['id', 'code', 'name']),
            'roles' => $this->roleNames(),
            'can' => [
                'create' => $authUser?->can('create', User::class) ?? false,
                'update' => $authUser?->can('update', new User) ?? false,
                'delete' => $authUser?->can('delete', new User) ?? false,
                'restore' => $authUser?->can('viewTrashed', User::class) ?? false,
            ],
        ]);
    }

    /**
     * Show the form for creating a user.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('admin/users/Create', [
            'departments' => $this->assignableDepartments(),
            'roles' => $request->user()?->can('assignRoles', User::class) ? $this->roleNames() : null,
        ]);
    }

    /**
     * Store a new user with the configured default password.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $defaultPassword = config('auth.default_user_password');

        if (! is_string($defaultPassword) || $defaultPassword === '') {
            throw new RuntimeException('Set DEFAULT_USER_PASSWORD in .env before creating users.');
        }

        $user = DB::transaction(function () use ($request, $defaultPassword): User {
            $user = User::create([
                ...$request->safe()->only(['name', 'email', 'department_id']),
                'password' => $defaultPassword,
            ]);

            if ($request->has('roles')) {
                $user->syncRoles($request->validated('roles'));
            }

            return $user;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pengguna :name dibuat dengan password default.', ['name' => $user->name])]);

        return to_route('admin.users.index');
    }

    /**
     * Show the form for editing the user.
     */
    public function edit(Request $request, User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('admin/users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'department_id' => $user->department_id,
                'is_active' => $user->is_active,
                'roles' => $user->roles()->pluck('name')->all(),
            ],
            'departments' => $this->assignableDepartments($user),
            'roles' => $request->user()?->can('assignRoles', User::class) ? $this->roleNames() : null,
            'isSelf' => $user->is($request->user()),
        ]);
    }

    /**
     * Update the user.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $user->update($request->safe()->only(['name', 'email', 'department_id', 'is_active']));

            if ($request->has('roles')) {
                $user->syncRoles($request->validated('roles'));
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pengguna :name diperbarui.', ['name' => $user->name])]);

        return to_route('admin.users.index');
    }

    /**
     * Soft-delete the user. Role assignments are kept for history.
     */
    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        if ($user->isLastRoleManager()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Pengguna ini satu-satunya pengelola role yang aktif dan tidak boleh dihapus.')]);

            return back();
        }

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pengguna :name dihapus.', ['name' => $user->name])]);

        return back();
    }

    /**
     * Restore a soft-deleted user.
     */
    public function restore(User $user): RedirectResponse
    {
        Gate::authorize('restore', $user);

        $user->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pengguna :name dipulihkan.', ['name' => $user->name])]);

        return back();
    }

    /**
     * Active departments, plus the user's current one even if it was deactivated.
     *
     * @return Collection<int, Department>
     */
    private function assignableDepartments(?User $user = null): Collection
    {
        return Department::query()
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->when($user?->department_id, fn ($query, int $departmentId) => $query->orWhere('id', $departmentId)))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->toBase();
    }

    /**
     * @return list<string>
     */
    private function roleNames(): array
    {
        return array_values(Role::where('guard_name', 'web')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): string => $role->name)
            ->all());
    }
}
