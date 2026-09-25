<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    /**
     * Determine whether the user can list roles.
     */
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::RolesManage->value);
    }

    /**
     * Determine whether the user can create roles.
     */
    public function create(User $user): bool
    {
        return $user->checkPermissionTo(Permission::RolesManage->value);
    }

    /**
     * Determine whether the user can update the role.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->checkPermissionTo(Permission::RolesManage->value);
    }

    /**
     * Determine whether the user can delete the role.
     */
    public function delete(User $user, Role $role): bool
    {
        return $user->checkPermissionTo(Permission::RolesManage->value);
    }
}
