<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\SystemRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds every permission and the initial (provisional) roles.
 *
 * Safe to re-run: permissions are created if missing and the admin role is
 * re-synced to hold all of them. Other roles are only seeded on first run so
 * changes made from the Role page are not overwritten.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Initial permissions per non-admin role. The work-orders.view-all,
     * work-orders.export, and work-orders.comment grants are provisional
     * until the real WO flow defines who oversees whom.
     *
     * @var array<string, list<Permission>>
     */
    private const array INITIAL_ROLES = [
        'pemohon' => [
            Permission::DepartmentsView,
            Permission::WorkOrdersView,
            Permission::WorkOrdersCreate,
            Permission::WorkOrdersUpdate,
            Permission::WorkOrdersComment,
        ],
        'approver' => [
            Permission::DepartmentsView,
            Permission::UsersView,
            Permission::WorkOrdersView,
            Permission::WorkOrdersViewAll,
            Permission::WorkOrdersUpdate,
            Permission::WorkOrdersExport,
            Permission::WorkOrdersComment,
        ],
        'keuangan' => [
            Permission::DepartmentsView,
            Permission::WorkOrdersView,
            Permission::WorkOrdersViewAll,
            Permission::WorkOrdersExport,
            Permission::WorkOrdersComment,
        ],
        'viewer' => [
            Permission::DepartmentsView,
            Permission::WorkOrdersView,
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

        Role::findOrCreate(SystemRole::Admin->value, 'web')->syncPermissions(Permission::values());

        foreach (self::INITIAL_ROLES as $name => $permissions) {
            $role = Role::findOrCreate($name, 'web');

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions(array_map(fn (Permission $permission): string => $permission->value, $permissions));
            }
        }
    }
}
