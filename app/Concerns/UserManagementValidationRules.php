<?php

namespace App\Concerns;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Spatie\Permission\Models\Role;

/**
 * Rules shared by the admin user create and update requests.
 */
trait UserManagementValidationRules
{
    use ProfileValidationRules;

    /**
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function userManagementRules(?User $user = null): array
    {
        return [
            ...$this->profileRules($user?->id, $this->user()?->can('viewTrashed', User::class) ?? false),
            'department_id' => ['required', 'integer', $this->assignableDepartmentRule($user)],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'distinct', Rule::exists(Role::class, 'name')->where('guard_name', 'web')],
        ];
    }

    /**
     * Only active, non-deleted departments can be assigned; a user may keep
     * their current department even after it was deactivated.
     */
    private function assignableDepartmentRule(?User $user): Exists
    {
        return Rule::exists(Department::class, 'id')
            ->withoutTrashed()
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->when($user?->department_id, fn ($query, int $departmentId) => $query->orWhere('id', $departmentId)));
    }

    /**
     * Whether assigning the submitted roles to a user would lose the role
     * management permission.
     */
    protected function submittedRolesRevokeRoleManagement(User $user): bool
    {
        if (! $this->has('roles')) {
            return false;
        }

        if ($user->getDirectPermissions()->contains('name', Permission::RolesManage->value)) {
            return false;
        }

        return ! Role::whereIn('name', (array) $this->input('roles'))
            ->whereRelation('permissions', 'name', Permission::RolesManage->value)
            ->exists();
    }

    /**
     * Only role managers may send the roles field.
     */
    protected function mayAssignSubmittedRoles(): bool
    {
        return ! $this->has('roles') || ($this->user()?->can('assignRoles', User::class) ?? false);
    }
}
