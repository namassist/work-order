<?php

namespace App\Concerns;

use App\Models\Department;
use App\Models\WorkOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Who requested a work order (FLOW.md v2 §4): an IC department and the name
 * of the IC contact, both required, plus the optional PIC Work Order name.
 * The department may change only until the first submission, since the
 * number carries its code from then on; the names stay editable while the
 * work order is (Draft and Ditolak).
 */
trait WorkOrderRequesterRules
{
    /**
     * @param  WorkOrder|null  $workOrder  the work order being edited; null on create
     * @return array<string, array<int, ValidationRule|string|Exists>>
     */
    protected function requesterRules(?WorkOrder $workOrder = null): array
    {
        return [
            'requester_department_id' => $workOrder?->wasSubmitted()
                ? ['prohibited']
                : $this->requesterDepartmentRules($workOrder?->requester_department_id),
            'requester_name' => ['required', 'string', 'max:150'],
            'pic_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * An active department of a client company, the side that requests
     * work, or the one the work order already has.
     *
     * @return array<int, string|Exists>
     */
    private function requesterDepartmentRules(?int $currentId): array
    {
        return [
            'required',
            'integer',
            Rule::exists(Department::class, 'id')->where(fn ($query) => $query
                ->whereIn('company_id', fn ($companies) => $companies->select('id')->from('companies')->where('is_client', true))
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))
                    ->when($currentId, fn ($query, int $id) => $query->orWhere('id', $id)))),
        ];
    }

    /**
     * Trimmed names, so a blank contact name counts as missing and a blank
     * PIC name is stored as none.
     */
    protected function prepareRequesterForValidation(): void
    {
        foreach (['requester_name', 'pic_name'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }
}
