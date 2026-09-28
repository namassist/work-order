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
     * department never changes; the koordinator who entered an on-behalf
     * work order (Draft or Ditolak) may correct its requester within that department.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workOrder = $this->workOrder();

        if (! $this->correctsRequester()) {
            return [...$this->workOrderRules($workOrder), ...$this->prohibitedRequesterRules(withDepartment: true)];
        }

        return [
            ...$this->workOrderRules($workOrder),
            'requester_department_id' => ['prohibited'],
            ...$this->requesterRules($workOrder->requester_department_id),
        ];
    }

    /**
     * Whether the request sets the requester, which only the user allowed to
     * correct it may send (WorkOrderPolicy::updateRequester()).
     */
    public function correctsRequester(): bool
    {
        return $this->has('requester_mode') && ($this->user()?->can('updateRequester', $this->workOrder()) ?? false);
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
