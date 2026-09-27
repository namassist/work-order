<?php

namespace App\Http\Requests\Admin;

use App\Concerns\DepartmentValidationRules;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDepartmentRequest extends FormRequest
{
    use DepartmentValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->department()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->departmentRules($this->department());
    }

    /**
     * A department's company decides which side of a work order it is on and
     * which roles its users may hold, so it cannot move to another company
     * once it has users or work orders (deleted ones included).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $department = $this->department();

                if ($validator->errors()->has('company_id') || (int) $this->input('company_id') === $department->company_id) {
                    return;
                }

                $inUse = User::withTrashed()->whereBelongsTo($department)->exists()
                    || WorkOrder::withTrashed()->where(fn ($query) => $query
                        ->where('requester_department_id', $department->id)
                        ->orWhere('target_department_id', $department->id))->exists();

                if ($inUse) {
                    $validator->errors()->add('company_id', __('Departemen yang sudah memiliki pengguna atau work order tidak dapat dipindah ke perusahaan lain.'));
                }
            },
        ];
    }

    /**
     * The department being updated.
     */
    public function department(): Department
    {
        /** @var Department */
        return $this->route('department');
    }
}
