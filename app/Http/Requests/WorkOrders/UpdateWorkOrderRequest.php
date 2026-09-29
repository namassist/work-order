<?php

namespace App\Http\Requests\WorkOrders;

use App\Concerns\WorkOrderRequesterRules;
use App\Concerns\WorkOrderValidationRules;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateWorkOrderRequest extends FormRequest
{
    use WorkOrderRequesterRules, WorkOrderValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        // The policy's response keeps its 404 for work orders the user cannot see.
        return Gate::inspect('update', $this->workOrder());
    }

    /**
     * Get the validation rules that apply to the request. The requester
     * department changes only in Draft (the number carries its code); the
     * contact and PIC names in Draft and Ditolak.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workOrder = $this->workOrder();

        return [...$this->workOrderRules($workOrder), ...$this->requesterRules($workOrder)];
    }

    /**
     * The work order being updated.
     */
    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }

    protected function prepareForValidation(): void
    {
        $this->prepareRequesterForValidation();
    }
}
