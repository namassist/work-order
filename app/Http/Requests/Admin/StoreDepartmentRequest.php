<?php

namespace App\Http\Requests\Admin;

use App\Concerns\DepartmentValidationRules;
use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    use DepartmentValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Department::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->departmentRules();
    }
}
