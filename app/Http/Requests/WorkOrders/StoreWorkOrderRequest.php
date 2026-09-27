<?php

namespace App\Http\Requests\WorkOrders;

use App\Concerns\WorkOrderRequesterRules;
use App\Concerns\WorkOrderValidationRules;
use App\Models\WorkOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkOrderRequest extends FormRequest
{
    use WorkOrderRequesterRules, WorkOrderValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', WorkOrder::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request. A koordinator
     * picks the requester department and requester; an IC user is the
     * requester, for their own department, and may not send those fields.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $documents = (new WorkOrder)->documentsCollection();

        return [
            ...$this->workOrderRules(),
            ...($this->isOnBehalf()
                ? ['requester_department_id' => $this->requesterDepartmentRules(), ...$this->requesterRules()]
                : $this->prohibitedRequesterRules(withDepartment: true)),
            'attachments' => ['nullable', 'array', 'max:'.$documents->maxFiles],
            'attachments.*' => $documents->fileRules(),
        ];
    }

    /**
     * Whether the work order is entered on behalf of an IC department.
     */
    public function isOnBehalf(): bool
    {
        return $this->user()?->can('createOnBehalf', WorkOrder::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareRequesterForValidation();
    }
}
