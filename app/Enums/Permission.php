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
    case WorkOrdersUpdate = 'work-orders.update';
    case WorkOrdersDelete = 'work-orders.delete';

    case WorkOrderCategoriesView = 'work-order-categories.view';
    case WorkOrderCategoriesCreate = 'work-order-categories.create';
    case WorkOrderCategoriesUpdate = 'work-order-categories.update';
    case WorkOrderCategoriesDelete = 'work-order-categories.delete';
    case WorkOrderCategoriesRestore = 'work-order-categories.restore';

    case ActivityLogView = 'activity-log.view';

    /**
     * The resource this permission belongs to, e.g. "departments".
     */
    public function resource(): string
    {
        return explode('.', $this->value)[0];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
