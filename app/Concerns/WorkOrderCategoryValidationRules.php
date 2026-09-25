<?php

namespace App\Concerns;

use App\Models\WorkOrderCategory;
use App\Rules\NotTakenByTrashed;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait WorkOrderCategoryValidationRules
{
    /**
     * Get the validation rules used to validate work order categories.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function workOrderCategoryRules(?int $categoryId = null): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique(WorkOrderCategory::class)->withoutTrashed()->ignore($categoryId),
                new NotTakenByTrashed(WorkOrderCategory::class, 'code', $this->user()?->can('viewTrashed', WorkOrderCategory::class) ?? false),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * Normalize the category code to upper case before validating.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }
}
