<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class ActivityPolicy
{
    /**
     * Determine whether the user can read the activity log and history panels.
     */
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ActivityLogView->value);
    }
}
