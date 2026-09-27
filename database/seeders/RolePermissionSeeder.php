<?php

namespace Database\Seeders;

use App\Enums\CompanyScope;
use App\Enums\Permission;
use App\Enums\SystemRole;
use Illuminate\Database\Seeder;
use LogicException;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds every permission and the initial (provisional) roles.
 *
 * Safe to re-run: permissions are created if missing and the admin role is
 * re-synced to hold all of them, for the executor company only. Other roles
 * are only seeded on first run so changes made from the Role page are not
 * overwritten.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Initial company scope and permissions per non-admin role (FLOW.md §3).
     * Only executor roles may hold internal-only permissions (run() refuses
     * anything else), so a role both sides share, like viewer, stays free of
     * them; an executor-only viewer would be a separate executor role. Export for pelaksana
     * and keuangan is provisional until FLOW.md settles who may export.
     *
     * @var array<string, array{scope: CompanyScope|null, permissions: list<Permission>}>
     */
    protected const array INITIAL_ROLES = [
        'pemohon' => [
            'scope' => CompanyScope::Client,
            'permissions' => [
                Permission::WorkOrdersView,
                Permission::WorkOrdersCreate,
                Permission::WorkOrdersUpdate,
                Permission::WorkOrdersComment,
            ],
        ],
        'pelaksana' => [
            'scope' => CompanyScope::Executor,
            'permissions' => [
                Permission::DepartmentsView,
                Permission::UsersView,
                Permission::WorkOrdersView,
                Permission::WorkOrdersUpdate,
                Permission::WorkOrdersExport,
                Permission::WorkOrdersComment,
            ],
        ],
        'koordinator' => [
            'scope' => CompanyScope::Executor,
            'permissions' => [
                Permission::DepartmentsView,
                Permission::WorkOrdersView,
                Permission::WorkOrdersCreate,
                Permission::WorkOrdersUpdate,
                Permission::WorkOrdersComment,
            ],
        ],
        'keuangan' => [
            'scope' => CompanyScope::Executor,
            'permissions' => [
                Permission::DepartmentsView,
                Permission::WorkOrdersView,
                Permission::WorkOrdersViewAll,
                Permission::WorkOrdersExport,
                Permission::WorkOrdersComment,
            ],
        ],
        'viewer' => [
            'scope' => null,
            'permissions' => [
                Permission::WorkOrdersView,
            ],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (Permission::values() as $name) {
            PermissionModel::findOrCreate($name, 'web');
        }

        $registrar->forgetCachedPermissions();

        $this->ensureInternalPermissionsOnlyForExecutorRoles();

        $admin = Role::findOrCreate(SystemRole::Admin->value, 'web');
        $admin->forceFill(['company_scope' => CompanyScope::Executor->value])->save();
        $admin->syncPermissions(Permission::values());

        foreach (static::INITIAL_ROLES as $name => $initial) {
            $role = Role::findOrCreate($name, 'web');

            if ($role->wasRecentlyCreated) {
                $role->forceFill(['company_scope' => $initial['scope']?->value])->save();
                $role->syncPermissions(array_map(fn (Permission $permission): string => $permission->value, $initial['permissions']));
            }
        }
    }

    /**
     * @throws LogicException when a non-executor initial role lists an internal-only permission
     */
    private function ensureInternalPermissionsOnlyForExecutorRoles(): void
    {
        foreach (static::INITIAL_ROLES as $name => $initial) {
            if ($initial['scope'] === CompanyScope::Executor) {
                continue;
            }

            $internal = array_filter($initial['permissions'], fn (Permission $permission): bool => $permission->isInternalOnly());

            if ($internal !== []) {
                throw new LogicException("Initial role [{$name}] is not an executor role and cannot hold internal-only permissions.");
            }
        }
    }
}
