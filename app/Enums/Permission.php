<?php

namespace App\Enums;

/**
 * Permission names checked by the application code.
 *
 * Which role holds which permission is data (seeded, editable by admins),
 * but every permission listed here must have code that checks it.
 */
enum Permission: string
{
    case DepartmentsView = 'departments.view';
    case DepartmentsCreate = 'departments.create';
    case DepartmentsUpdate = 'departments.update';
    case DepartmentsDelete = 'departments.delete';
    case DepartmentsRestore = 'departments.restore';

    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';
    case UsersRestore = 'users.restore';

    case RolesManage = 'roles.manage';

    case WorkOrdersView = 'work-orders.view';
    case WorkOrdersCreate = 'work-orders.create';
    case WorkOrdersCreateOnBehalf = 'work-orders.create-on-behalf';
    case WorkOrdersUpdate = 'work-orders.update';
    case WorkOrdersDelete = 'work-orders.delete';
    case WorkOrdersRestore = 'work-orders.restore';
    case WorkOrdersViewAll = 'work-orders.view-all';
    case WorkOrdersExport = 'work-orders.export';
    case WorkOrdersComment = 'work-orders.comment';

    case WorkOrderCategoriesView = 'work-order-categories.view';
    case WorkOrderCategoriesCreate = 'work-order-categories.create';
    case WorkOrderCategoriesUpdate = 'work-order-categories.update';
    case WorkOrderCategoriesDelete = 'work-order-categories.delete';
    case WorkOrderCategoriesRestore = 'work-order-categories.restore';

    case CompaniesView = 'companies.view';
    case CompaniesCreate = 'companies.create';
    case CompaniesUpdate = 'companies.update';
    case CompaniesDelete = 'companies.delete';
    case CompaniesRestore = 'companies.restore';

    case ActivityLogView = 'activity-log.view';

    /**
     * The resource this permission belongs to, e.g. "departments".
     */
    public function resource(): string
    {
        return explode('.', $this->value)[0];
    }

    /**
     * Whether only users of the executor company may use this permission:
     * master data, user and role management, the activity log, seeing every
     * department's work orders, and entering work orders on behalf of IC. Only executor-scoped roles may include
     * it, and client company users never hold it, even through a direct
     * grant (see User::hasPermissionTo()).
     */
    public function isInternalOnly(): bool
    {
        return in_array($this, [self::WorkOrdersViewAll, self::WorkOrdersCreateOnBehalf], true) || in_array($this->resource(), [
            'departments',
            'users',
            'roles',
            'work-order-categories',
            'companies',
            'activity-log',
        ], true);
    }

    /**
     * @return list<string>
     */
    public static function internalOnlyValues(): array
    {
        return array_values(array_map(
            fn (self $permission): string => $permission->value,
            array_filter(self::cases(), fn (self $permission): bool => $permission->isInternalOnly()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
