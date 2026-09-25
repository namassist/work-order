<?php

namespace App\Concerns;

use App\Models\Department;
use App\Rules\NotTakenByTrashed;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait DepartmentValidationRules
{
    /**
     * Get the validation rules used to validate departments.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function departmentRules(?int $departmentId = null): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique(Department::class)->withoutTrashed()->ignore($departmentId),
                new NotTakenByTrashed(Department::class, 'code', $this->user()?->can('viewTrashed', Department::class) ?? false),
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * Normalize the department code to upper case before validating.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }
}
