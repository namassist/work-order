<?php

namespace App\Concerns;

use App\Models\Company;
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
    protected function departmentRules(?Department $department = null): array
    {
        return [
            // Active companies, plus the one the department already has.
            'company_id' => [
                'required',
                'integer',
                Rule::exists(Company::class, 'id')->where(fn ($query) => $query
                    ->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))
                    ->when($department?->company_id, fn ($query, int $id) => $query->orWhere('id', $id))),
            ],
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique(Department::class)->withoutTrashed()->ignore($department?->id),
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
