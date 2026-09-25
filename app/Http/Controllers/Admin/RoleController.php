<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    use LogsAuditChanges;

    /**
     * List roles with their user and permission counts.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->withCount(['users', 'permissions'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'users_count' => $role->users_count,
                'permissions_count' => $role->permissions_count,
                'is_system' => $role->name === SystemRole::Admin->value,
            ]);

        return Inertia::render('admin/roles/Index', [
            'roles' => $roles,
        ]);
    }

    /**
     * Show the form for creating a role.
     */
    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('admin/roles/Form', [
            'role' => null,
            'permissionGroups' => $this->permissionGroups(),
        ]);
    }

    /**
     * Store a new role.
     */
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = DB::transaction(function () use ($request): Role {
            $role = new Role(['name' => $request->validated('name'), 'guard_name' => 'web']);
            $role->save();
            $role->syncPermissions($request->validated('permissions'));
            $this->logAuditChange($role, AuditEvent::Created, [], $this->auditState($role));

            return $role;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role :name dibuat.', ['name' => $role->name])]);

        return to_route('admin.roles.index');
    }

    /**
     * Show the form for editing the role.
     */
    public function edit(Role $role): Response
    {
        Gate::authorize('update', $role);

        return Inertia::render('admin/roles/Form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions()->pluck('name')->all(),
                'is_system' => $role->name === SystemRole::Admin->value,
            ],
            'permissionGroups' => $this->permissionGroups(),
        ]);
    }

    /**
     * Update the role and its permissions.
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        DB::transaction(function () use ($request, $role): void {
            $before = $this->auditState($role);
            $role->update(['name' => $request->validated('name')]);
            $role->syncPermissions($request->validated('permissions'));
            $this->logAuditChange($role, AuditEvent::Updated, $before, $this->auditState($role));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role :name diperbarui.', ['name' => $role->name])]);

        return to_route('admin.roles.index');
    }

    /**
     * Delete the role, unless it is the admin role or still assigned to any
     * user (including soft-deleted users, whose assignments are kept).
     */
    public function destroy(Role $role): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $message = match (true) {
            $role->name === SystemRole::Admin->value => __('Role admin tidak boleh dihapus.'),
            User::withTrashed()->whereHas('roles', fn ($query) => $query->whereKey($role->getKey()))->exists() => __('Role :name masih dipakai oleh pengguna (termasuk pengguna terhapus) dan tidak boleh dihapus.', ['name' => $role->name]),
            default => null,
        };

        if ($message !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back();
        }

        DB::transaction(function () use ($role): void {
            $this->logAuditChange($role, AuditEvent::Deleted, $this->auditState($role), []);
            $role->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role :name dihapus.', ['name' => $role->name])]);

        return to_route('admin.roles.index');
    }

    /**
     * The role as recorded in the audit log.
     *
     * @return array{name: string, permissions: list<string>}
     */
    private function auditState(Role $role): array
    {
        return [
            'name' => $role->name,
            'permissions' => array_values(PermissionModel::query()
                ->whereRelation('roles', 'roles.id', $role->id)
                ->orderBy('name')
                ->get()
                ->map(fn (PermissionModel $permission): string => $permission->name)
                ->all()),
        ];
    }

    /**
     * All permissions grouped by resource, in declaration order.
     *
     * @return array<string, list<string>>
     */
    private function permissionGroups(): array
    {
        $groups = [];

        foreach (Permission::cases() as $permission) {
            $groups[$permission->resource()][] = $permission->value;
        }

        return $groups;
    }
}
