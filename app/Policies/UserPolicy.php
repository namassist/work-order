<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determine whether the user can list users.
     */
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::UsersView->value);
    }

    /**
     * Determine whether the user can list soft-deleted users.
     */
    public function viewTrashed(User $user): bool
    {
        return $user->checkPermissionTo(Permission::UsersRestore->value);
    }

    /**
     * Determine whether the user can create users.
     */
    public function create(User $user): bool
    {
        return $user->checkPermissionTo(Permission::UsersCreate->value);
    }

    /**
     * Determine whether the user can update the given user.
     */
    public function update(User $user, User $model): bool
    {
        return $user->checkPermissionTo(Permission::UsersUpdate->value);
    }

    /**
     * Determine whether the user can assign roles to users.
     */
    public function assignRoles(User $user): bool
    {
        return $user->checkPermissionTo(Permission::RolesManage->value);
    }

    /**
     * Determine whether the user can soft-delete the given user.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->isNot($model) && $user->checkPermissionTo(Permission::UsersDelete->value);
    }

    /**
     * Determine whether the user can restore the given user.
     */
    public function restore(User $user, User $model): bool
    {
        return $user->checkPermissionTo(Permission::UsersRestore->value);
    }

    /**
     * Determine whether the user can open the Pendaftaran page.
     */
    public function viewRegistrations(User $user): bool
    {
        return $user->checkPermissionTo(Permission::RegistrationsView->value);
    }

    /**
     * Determine whether the user can approve the registration. Whether its
     * state still allows it (pending, or rejected on re-review) is checked
     * under a lock by ApproveRegistration. Accounts that never registered
     * themselves are not registrations: 404.
     */
    public function approveRegistration(User $user, User $model): Response
    {
        return $this->reviewRegistration($user, $model, Permission::RegistrationsApprove);
    }

    /**
     * Determine whether the user can reject the registration (while pending,
     * checked by RejectRegistration).
     */
    public function rejectRegistration(User $user, User $model): Response
    {
        return $this->reviewRegistration($user, $model, Permission::RegistrationsReject);
    }

    private function reviewRegistration(User $user, User $model, Permission $permission): Response
    {
        if ($model->registered_at === null) {
            return Response::denyAsNotFound();
        }

        return $user->checkPermissionTo($permission->value) ? Response::allow() : Response::deny();
    }

    /**
     * Permanent deletion is not offered anywhere in the application.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
