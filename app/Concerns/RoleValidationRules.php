<?php

namespace App\Concerns;

use App\Enums\CompanyScope;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Role;

trait RoleValidationRules
{
    /**
     * Get the validation rules used to validate roles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function roleRules(?Role $role = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique(Role::class, 'name')->where('guard_name', 'web')->ignore($role),
            ],
            'label' => ['required', 'string', 'max:100'],
            'company_scope' => ['nullable', Rule::enum(CompanyScope::class)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::values())],
        ];
    }

    /**
     * Checks on the role's company scope: the admin role belongs to the
     * executor, only executor roles hold internal-only permissions (a role
     * for any company could reach client users), and a role cannot get a
     * scope that excludes users who already hold it.
     *
     * @return list<callable(Validator): void>
     */
    protected function companyScopeChecks(?Role $role = null): array
    {
        return [
            function (Validator $validator) use ($role): void {
                if ($role?->name === SystemRole::Admin->value && $this->input('company_scope') !== CompanyScope::Executor->value) {
                    $validator->errors()->add('company_scope', __('Role admin hanya untuk perusahaan pelaksana.'));
                }
            },
            function (Validator $validator): void {
                if ($this->input('company_scope') === CompanyScope::Executor->value) {
                    return;
                }

                $internal = array_values(array_intersect((array) $this->input('permissions', []), Permission::internalOnlyValues()));

                if ($internal !== []) {
                    $validator->errors()->add('permissions', __('Izin internal hanya untuk role perusahaan pelaksana: :permissions.', ['permissions' => implode(', ', $internal)]));
                }
            },
            function (Validator $validator) use ($role): void {
                $scope = CompanyScope::tryFrom((string) $this->input('company_scope'));

                if (! $role instanceof Role || $scope === null || $validator->errors()->has('company_scope')) {
                    return;
                }

                $misfits = User::withTrashed()
                    ->whereHas('roles', fn (Builder $roles) => $roles->whereKey($role->getKey()))
                    ->whereHas('department.company', fn (Builder $company) => $company->where('is_client', $scope !== CompanyScope::Client))
                    ->count();

                if ($misfits > 0) {
                    $validator->errors()->add('company_scope', __(':count pengguna dengan role ini bukan dari :scope. Ubah role mereka terlebih dahulu.', [
                        'count' => $misfits,
                        'scope' => mb_strtolower($scope->label()),
                    ]));
                }
            },
        ];
    }
}
