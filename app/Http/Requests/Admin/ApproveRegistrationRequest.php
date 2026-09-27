<?php

namespace App\Http\Requests\Admin;

use App\Concerns\UserManagementValidationRules;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Role;

class ApproveRegistrationRequest extends FormRequest
{
    use UserManagementValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        return Gate::inspect('approveRegistration', $this->registration());
    }

    /**
     * Without a department in the request the account keeps its own.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('department_id')) {
            $this->merge(['department_id' => $this->registration()->department_id]);
        }
    }

    /**
     * At least one role; the department may only be corrected within the
     * company the email domain chose (an active one, or the current one).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $registration = $this->registration();

        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::exists(Role::class, 'name')->where('guard_name', 'web')],
            'department_id' => [
                'required',
                'integer',
                Rule::exists(Department::class, 'id')
                    ->where('company_id', $registration->department->company_id)
                    ->where(fn ($query) => $query
                        ->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))
                        ->orWhere('id', $registration->department_id)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'department_id.exists' => __('Pilih departemen aktif dari perusahaan :company.', ['company' => $this->registration()->department->company->name]),
        ];
    }

    /**
     * Roles must fit the company, and approval never grants role management
     * (so never admin): that stays on the Users page, for role managers.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->roleFitCheck(),
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['roles', 'roles.*'])) {
                    return;
                }

                $managing = Role::query()
                    ->where('guard_name', 'web')
                    ->whereIn('name', (array) $this->input('roles'))
                    ->whereRelation('permissions', 'name', Permission::RolesManage->value)
                    ->orderBy('name')
                    ->pluck('name');

                if ($managing->isNotEmpty()) {
                    $validator->errors()->add('roles', __('Role :roles tidak dapat diberikan saat menyetujui pendaftaran; berikan lewat halaman Pengguna.', [
                        'roles' => $managing->implode(', '),
                    ]));
                }
            },
        ];
    }

    /**
     * The registration being approved.
     */
    public function registration(): User
    {
        /** @var User */
        return $this->route('user');
    }
}
