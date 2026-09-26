<?php

namespace App\Http\Requests\WorkOrders;

use App\Models\WorkOrder;
use App\States\WorkOrder\WorkOrderStatus;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TransitionWorkOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        // The policy's response keeps its 404 for other departments' work orders.
        return Gate::inspect('transition', $this->workOrder());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $target = WorkOrderStatus::fromName((string) $this->input('status'));

        return [
            'status' => ['required', 'string', Rule::in($this->workOrder()->status->transitionableStates())],
            'note' => [$target?->requiresNote() ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => __('Status tidak dapat diubah dari :status.', ['status' => $this->workOrder()->status->label()]),
        ];
    }

    /**
     * The work order whose status changes.
     */
    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }
}
