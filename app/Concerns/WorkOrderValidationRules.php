<?php

namespace App\Concerns;

use App\Enums\WorkOrderUrgency;
use App\Models\Department;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Support\DisplayDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;

trait WorkOrderValidationRules
{
    /**
     * Get the validation rules used to validate work orders.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string|mixed>>
     */
    protected function workOrderRules(?WorkOrder $workOrder = null): array
    {
        $currentCategoryId = $workOrder?->work_order_category_id;
        $currentTargetId = $workOrder?->target_department_id;
        $currentTargetDate = $workOrder?->target_date?->toDateString();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'work_order_category_id' => [
                'required',
                'integer',
                // Active categories, plus the one the work order already has.
                Rule::exists(WorkOrderCategory::class, 'id')->where(fn ($query) => $query
                    ->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))
                    ->when($currentCategoryId, fn ($query, int $id) => $query->orWhere('id', $id))),
            ],
            'target_department_id' => [
                'nullable',
                'integer',
                // Departments of the executor company that are active, plus
                // the one the work order already has (FLOW.md §2).
                Rule::exists(Department::class, 'id')->where(fn ($query) => $query
                    ->whereIn('company_id', fn ($companies) => $companies->select('id')->from('companies')->where('is_client', false))
                    ->where(fn ($query) => $query
                        ->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))
                        ->when($currentTargetId, fn ($query, int $id) => $query->orWhere('id', $id)))),
            ],
            'urgency' => ['required', Rule::enum(WorkOrderUrgency::class)],
            'target_date' => [
                'nullable',
                'date_format:Y-m-d',
                // Only a new or changed date must not be in the past, so old drafts stay editable.
                Rule::when(
                    fn (Fluent $input): bool => filled($input->get('target_date')) && $input->get('target_date') !== $currentTargetDate,
                    ['after_or_equal:'.DisplayDate::today()],
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_date.after_or_equal' => __('Target selesai tidak boleh sebelum hari ini.'),
            'target_department_id.exists' => __('Pilih departemen aktif dari perusahaan pelaksana.'),
            'requester_department_id.exists' => __('Pilih departemen aktif dari perusahaan klien.'),
            'requester_id.exists' => __('Pilih akun aktif dari departemen pemohon.'),
            'requester_id.required_if' => __('Pilih akun pemohon.'),
            'requester_name.required_if' => __('Isi nama kontak pemohon.'),
        ];
    }
}
