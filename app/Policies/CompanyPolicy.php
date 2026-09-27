<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    /**
     * Determine whether the user can list companies.
     */
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::CompaniesView->value);
    }

    /**
     * Determine whether the user can list soft-deleted companies.
     */
    public function viewTrashed(User $user): bool
    {
        return $user->checkPermissionTo(Permission::CompaniesRestore->value);
    }

    /**
     * Determine whether the user can create companies.
     */
    public function create(User $user): bool
    {
        return $user->checkPermissionTo(Permission::CompaniesCreate->value);
    }

    /**
     * Determine whether the user can update the company.
     */
    public function update(User $user, Company $company): bool
    {
        return $user->checkPermissionTo(Permission::CompaniesUpdate->value);
    }

    /**
     * Determine whether the user can soft-delete the company.
     */
    public function delete(User $user, Company $company): bool
    {
        return $user->checkPermissionTo(Permission::CompaniesDelete->value);
    }

    /**
     * Determine whether the user can restore the company.
     */
    public function restore(User $user, Company $company): bool
    {
        return $user->checkPermissionTo(Permission::CompaniesRestore->value);
    }

    /**
     * Permanent deletion is not offered anywhere in the application.
     */
    public function forceDelete(User $user, Company $company): bool
    {
        return false;
    }
}
