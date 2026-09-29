<?php

namespace App\Actions\WorkOrders\Transitions;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Validation\ValidationException;

/**
 * Something a work order must have before a status change may happen, e.g.
 * a daily report before review (FLOW.md §5.1). Declared on the transition
 * (WorkOrderTransition::$requirements), resolved from the container, and
 * checked by TransitionWorkOrder with the work order locked, before the
 * status changes.
 */
interface TransitionRequirement
{
    /**
     * @throws ValidationException when the work order does not meet it, with a message for the user
     */
    public function ensureMet(WorkOrder $workOrder, User $user): void;
}
