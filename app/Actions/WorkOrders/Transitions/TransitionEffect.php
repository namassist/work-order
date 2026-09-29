<?php

namespace App\Actions\WorkOrders\Transitions;

use App\Models\User;
use App\Models\WorkOrder;

/**
 * Work that comes with a status change, e.g. generating the BAST when it is
 * submitted (FLOW.md §5.1, §8). Declared on the transition
 * (WorkOrderTransition::$effects), resolved from the container, and run by
 * TransitionWorkOrder right after the status changed, in the same
 * transaction: an exception rolls the status change back.
 */
interface TransitionEffect
{
    public function handle(WorkOrder $workOrder, User $user): void;
}
