<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    /**
     * Determine whether the user can list departments.
     */
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::DepartmentsView->value);
    }

    /**
     * Determine whether the user can list soft-deleted departments.
     */
    public function viewTrashed(User $user): bool
    {
        return $user->checkPermissionTo(Permission::DepartmentsRestore->value);
    }

    /**
     * Determine whether the user can create departments.
     */
    public function create(User $user): bool
    {
        return $user->checkPermissionTo(Permission::DepartmentsCreate->value);
    }

    /**
     * Determine whether the user can update the department.
     */
    public function update(User $user, Department $department): bool
    {
        return $user->checkPermissionTo(Permission::DepartmentsUpdate->value);
    }

    /**
     * Determine whether the user can soft-delete the department.
     */
    public function delete(User $user, Department $department): bool
    {
        return $user->checkPermissionTo(Permission::DepartmentsDelete->value);
    }

    /**
     * Determine whether the user can restore the department.
     */
    public function restore(User $user, Department $department): bool
    {
        return $user->checkPermissionTo(Permission::DepartmentsRestore->value);
    }

    /**
     * Permanent deletion is not offered anywhere in the application.
     */
    public function forceDelete(User $user, Department $department): bool
    {
        return false;
    }
}
