<?php

namespace App\Http\Requests\WorkOrders;

use App\Models\WorkOrder;
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
        $workOrder = $this->workOrder();
        $to = (string) $this->input('status');

        // The policy's response keeps its 404 for work orders the user cannot see.
        // A status the work order cannot move to is refused by validation, with a
        // message, for users who may change its status at all.
        return in_array($to, $workOrder->status->transitionableStates(), true)
            ? Gate::inspect('transition', [$workOrder, $to])
            : Gate::inspect('changeStatus', $workOrder);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $transition = $this->workOrder()->status->transitionFor((string) $this->input('status'));

        return [
            'status' => ['required', 'string', Rule::in($this->workOrder()->status->transitionableStates())],
            'note' => [$transition?->requiresNote ? 'required' : 'nullable', 'string', 'max:1000'],
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
