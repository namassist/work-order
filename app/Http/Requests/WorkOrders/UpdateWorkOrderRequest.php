<?php

namespace App\Http\Requests\WorkOrders;

use App\Concerns\WorkOrderValidationRules;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateWorkOrderRequest extends FormRequest
{
    use WorkOrderValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        // The policy's response keeps its 404 for other departments' work orders.
        return Gate::inspect('update', $this->workOrder());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->workOrderRules($this->workOrder());
    }

    /**
     * The work order being updated.
     */
    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }
}
