<?php

namespace App\Concerns;

use App\Enums\CompanyScope;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;
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
     * The user's roles must fit their department's company (CompanyScope):
     * the submitted roles, or, when roles are not sent, the ones the user
     * already holds, so moving someone to another company's department
     * cannot leave them with roles of the wrong side.
     *
     * @return callable(Validator): void
     */
    protected function roleFitCheck(?User $user = null): callable
    {
        return function (Validator $validator) use ($user): void {
            if ($validator->errors()->hasAny(['department_id', 'roles', 'roles.*'])) {
                return;
            }

            $company = Department::withTrashed()->with('company')->find($this->integer('department_id'))?->company;

            if ($company === null) {
                return;
            }

            $roles = $this->has('roles')
                ? Role::query()->where('guard_name', 'web')->whereIn('name', (array) $this->input('roles'))->get()
                : ($user instanceof User ? $user->roles : new Collection);

            $misfits = $roles
                ->reject(fn (Role $role): bool => CompanyScope::fits(CompanyScope::tryFrom((string) $role->getAttribute('company_scope')), $company))
                ->pluck('name')
                ->all();

            if ($misfits === []) {
                return;
            }

            $validator->errors()->add($this->has('roles') ? 'roles' : 'department_id', __('Role :roles tidak dapat diberikan kepada pengguna :company (:scope).', [
                'roles' => implode(', ', $misfits),
                'company' => $company->name,
                'scope' => mb_strtolower(CompanyScope::of($company)->label()),
            ]));
        };
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
