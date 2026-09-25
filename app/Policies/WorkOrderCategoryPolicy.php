<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WorkOrderCategory;

class WorkOrderCategoryPolicy
{
    /**
     * Determine whether the user can list work order categories.
     */
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrderCategoriesView->value);
    }

    /**
     * Determine whether the user can list soft-deleted work order categories.
     */
    public function viewTrashed(User $user): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrderCategoriesRestore->value);
    }

    /**
     * Determine whether the user can create work order categories.
     */
    public function create(User $user): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrderCategoriesCreate->value);
    }

    /**
     * Determine whether the user can update the work order category.
     */
    public function update(User $user, WorkOrderCategory $category): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrderCategoriesUpdate->value);
    }

    /**
     * Determine whether the user can soft-delete the work order category.
     */
    public function delete(User $user, WorkOrderCategory $category): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrderCategoriesDelete->value);
    }

    /**
     * Determine whether the user can restore the work order category.
     */
    public function restore(User $user, WorkOrderCategory $category): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrderCategoriesRestore->value);
    }

    /**
     * Permanent deletion is not offered anywhere in the application.
     */
    public function forceDelete(User $user, WorkOrderCategory $category): bool
    {
        return false;
    }
}
