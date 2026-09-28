<?php

namespace App\Concerns;

use App\Models\WorkOrder;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * For the requests of statuses entered through their own form (Penagihan,
 * Selesai; WorkOrderStatus::transitionForm()), the same answers as
 * TransitionWorkOrderRequest: the side of the destination may make the
 * change; a status the work order cannot move to from where it is gets a
 * validation message for users on any side (e.g. a page loaded before
 * someone else changed the status), and a 403 for everyone else.
 */
trait AuthorizesFormTransition
{
    abstract public function workOrder(): WorkOrder;

    protected function authorizeTransitionTo(string $to): Response
    {
        $workOrder = $this->workOrder();

        // The policy's response keeps its 404 for work orders the user cannot see.
        return $this->canMoveTo($to)
            ? Gate::inspect('transition', [$workOrder, $to])
            : Gate::inspect('changeStatus', $workOrder);
    }

    /**
     * @return list<callable(Validator): void>
     */
    protected function transitionableCheck(string $to): array
    {
        return [function (Validator $validator) use ($to): void {
            if (! $this->canMoveTo($to)) {
                $validator->errors()->add('status', __('Status tidak dapat diubah dari :status.', ['status' => $this->workOrder()->status->label()]));
            }
        }];
    }

    private function canMoveTo(string $to): bool
    {
        return in_array($to, $this->workOrder()->status->transitionableStates(), true);
    }
}
