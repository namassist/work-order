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
     * Get the validation rules that apply to the request: the Admin WO
     * enters the IC requester department and contact (FLOW.md v2 §4).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $documents = (new WorkOrder)->documentsCollection();

        return [
            ...$this->workOrderRules(),
            ...$this->requesterRules(),
            'attachments' => ['nullable', 'array', 'max:'.$documents->maxFiles],
            'attachments.*' => $documents->fileRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareRequesterForValidation();
    }
}
