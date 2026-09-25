<?php

namespace App\Concerns;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
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
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::values())],
        ];
    }
}
