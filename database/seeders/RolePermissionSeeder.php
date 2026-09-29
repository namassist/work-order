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
 * Seeds every permission and the initial (provisional) roles, by slug with
 * a display label (App\Support\RoleLabel).
 *
 * Safe to re-run: permissions are created if missing and the admin role is
 * re-synced to hold all of them, for the executor company only. Other roles
 * are only seeded on first run so changes made from the Role page are not
 * overwritten.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * The admin role's display label (FLOW.md §3: "System admin").
     */
    public const string ADMIN_LABEL = 'Admin Sistem';

    /**
     * Initial label, company scope, and permissions per non-admin role
     * (FLOW.md v2 §3), keyed by slug. Every initial role is for the executor
     * company: IC never logs in, so no role is assigned to client users.
     * Only executor roles may hold internal-only permissions (run() refuses
     * anything else). PROVISIONAL until step 3 adds the v2 transitions:
     * Admin WO is the requester side (work-orders.update), Lead Operational
     * the executor side (work-orders.process), Finance confirms payment;
     * PIC Timesheet, Rental, and Direktur only read and comment for now.
     * Export for Admin WO, Lead Operational, Direktur, and Finance is
     * provisional too.
     *
     * @var array<string, array{label: string, scope: CompanyScope|null, permissions: list<Permission>}>
     */
    protected const array INITIAL_ROLES = [
        'admin-wo' => [
            'label' => 'Admin WO',
            'scope' => CompanyScope::Executor,
            'permissions' => [
                Permission::WorkOrdersView,
                Permission::WorkOrdersCreate,
                Permission::WorkOrdersUpdate,
                Permission::WorkOrdersDelete,
                Permission::WorkOrdersComment,
                Permission::WorkOrdersExport,
            ],
        ],
        'lead-operational' => [
            'label' => 'Lead Operational',
            'scope' => CompanyScope::Executor,
            'permissions' => [
                Permission::WorkOrdersView,
                Permission::WorkOrdersProcess,
                Permission::WorkOrdersComment,
                Permission::WorkOrdersExport,
            ],
        ],
        'pic-timesheet' => [
            'label' => 'PIC Timesheet',
            'scope' => CompanyScope::Executor,
            'permissions' => [
                Permission::WorkOrdersView,
                Permission::WorkOrdersComment,
            ],
        ],
        'rental' => [
            'label' => 'Rental',
            'scope' => CompanyScope::Executor,
            'permissions' => [
                Permission::WorkOrdersView,
                Permission::WorkOrdersComment,
            ],
        ],
        'direktur' => [
            'label' => 'Direktur',
            'scope' => CompanyScope::Executor,
            'permissions' => [
                Permission::WorkOrdersView,
                Permission::WorkOrdersComment,
                Permission::WorkOrdersExport,
            ],
        ],
        'finance' => [
            'label' => 'Finance',
            'scope' => CompanyScope::Executor,
            'permissions' => [
                Permission::WorkOrdersView,
                Permission::WorkOrdersConfirmPayment,
                Permission::WorkOrdersComment,
                Permission::WorkOrdersExport,
            ],
        ],
        'viewer' => [
            'label' => 'Viewer',
            'scope' => CompanyScope::Executor,
            'permissions' => [
                Permission::WorkOrdersView,
            ],
        ],
    ];

    /**
     * The v1 roles (FLOW.md v1 §3). A database that still has them cannot be
     * upgraded in place: a leftover pelaksana would keep work-orders.process
     * and act on every work order (see docs/DEPLOY.md).
     *
     * @var list<string>
     */
    public const array V1_ROLES = ['pemohon', 'koordinator', 'pelaksana', 'keuangan'];

    /**
     * Run the database seeds.
     *
     * @throws LogicException when a v1 role still exists
     */
    public function run(): void
    {
        $this->ensureNoV1Roles();

        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (Permission::values() as $name) {
            PermissionModel::findOrCreate($name, 'web');
        }

        $registrar->forgetCachedPermissions();

        $this->ensureInternalPermissionsOnlyForExecutorRoles();

        $admin = Role::findOrCreate(SystemRole::Admin->value, 'web');
        $admin->forceFill([
            'company_scope' => CompanyScope::Executor->value,
            'label' => $admin->getAttribute('label') ?? self::ADMIN_LABEL,
        ])->save();
        $admin->syncPermissions(Permission::values());

        foreach (static::INITIAL_ROLES as $name => $initial) {
            $role = Role::findOrCreate($name, 'web');

            if ($role->wasRecentlyCreated) {
                $role->forceFill(['label' => $initial['label'], 'company_scope' => $initial['scope']?->value])->save();
                $role->syncPermissions(array_map(fn (Permission $permission): string => $permission->value, $initial['permissions']));
            }
        }
    }

    /**
     * @throws LogicException when a v1 role still exists
     */
    private function ensureNoV1Roles(): void
    {
        $leftovers = Role::query()->whereIn('name', self::V1_ROLES)->orderBy('name')->pluck('name')->all();

        if ($leftovers !== []) {
            throw new LogicException('The database still has the v1 roles ['.implode(', ', $leftovers).']. '
                .'They cannot be upgraded in place to the v2 roles: start from a fresh database (see docs/DEPLOY.md).');
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
