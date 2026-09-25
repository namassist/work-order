<?php

namespace App\Http\Requests\Admin;

use App\Concerns\UserManagementValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    use UserManagementValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return ($this->user()?->can('update', $this->managedUser()) ?? false) && $this->mayAssignSubmittedRoles();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->userManagementRules($this->managedUser()),
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * Guard against self-deactivation and locking everyone out of role management.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->managedUser();
                $deactivating = ! $this->boolean('is_active');

                if ($deactivating && $user->is($this->user())) {
                    $validator->errors()->add('is_active', __('Anda tidak dapat menonaktifkan akun sendiri.'));

                    return;
                }

                if (! $user->isLastRoleManager()) {
                    return;
                }

                if ($deactivating) {
                    $validator->errors()->add('is_active', __('Pengguna ini satu-satunya pengelola role yang aktif dan tidak boleh dinonaktifkan.'));
                } elseif ($this->submittedRolesRevokeRoleManagement($user)) {
                    $validator->errors()->add('roles', __('Pengguna ini satu-satunya pengelola role yang aktif; hak kelola role tidak boleh dicabut.'));
                }
            },
        ];
    }

    /**
     * The user being updated.
     */
    public function managedUser(): User
    {
        /** @var User */
        return $this->route('user');
    }
}
