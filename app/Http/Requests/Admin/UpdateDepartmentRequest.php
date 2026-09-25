<?php

namespace App\Http\Requests\Admin;

use App\Concerns\DepartmentValidationRules;
use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        return $this->departmentRules($this->department()->id);
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
