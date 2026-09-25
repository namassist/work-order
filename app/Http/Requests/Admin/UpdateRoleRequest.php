<?php

namespace App\Http\Requests\Admin;

use App\Concerns\RoleValidationRules;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Role;

class UpdateRoleRequest extends FormRequest
{
    use RoleValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->role()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->roleRules($this->role());
    }

    /**
     * The admin role cannot be renamed or lose any permission, and no role
     * may drop role management if that leaves no active user holding it.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->revokesLastRoleManagement()) {
                    $validator->errors()->add('permissions', __('Role ini satu-satunya sumber hak kelola role bagi pengguna aktif; permission roles.manage tidak boleh dicabut.'));
                }
            },
            function (Validator $validator): void {
                if ($this->role()->name !== SystemRole::Admin->value) {
                    return;
                }

                if ($this->input('name') !== SystemRole::Admin->value) {
                    $validator->errors()->add('name', __('Role admin tidak boleh diganti namanya.'));
                }

                if (array_diff(Permission::values(), (array) $this->input('permissions', [])) !== []) {
                    $validator->errors()->add('permissions', __('Permission role admin tidak boleh dikurangi.'));
                }
            },
        ];
    }

    /**
     * Whether removing roles.manage from this role leaves no active user who
     * still gets it from another role or a direct grant.
     */
    private function revokesLastRoleManagement(): bool
    {
        $manage = Permission::RolesManage->value;
        $role = $this->role();

        if (in_array($manage, (array) $this->input('permissions', []), true) || ! $role->hasPermissionTo($manage)) {
            return false;
        }

        return ! User::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereHas('roles', fn (Builder $roles) => $roles
                    ->whereKeyNot($role->getKey())
                    ->whereRelation('permissions', 'name', $manage))
                ->orWhereRelation('permissions', 'name', $manage))
            ->exists();
    }

    /**
     * The role being updated.
     */
    public function role(): Role
    {
        /** @var Role */
        return $this->route('role');
    }
}
