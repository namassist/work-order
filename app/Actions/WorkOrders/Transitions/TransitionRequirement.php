<?php

namespace App\Actions\WorkOrders\Transitions;

use App\Models\User;
use App\Models\WorkOrder;

/**
 * Something a work order must have before a status change may happen, e.g.
 * a daily report before review (FLOW.md §5.1). Declared on the transition
 * (WorkOrderTransition::$requirements) and resolved from the container.
 * TransitionWorkOrder checks it with the work order locked, before the
 * status changes, and refuses the change with its reason; the detail page
 * shows the same reason on the disabled button.
 */
interface TransitionRequirement
{
    /**
     * Why the work order does not meet the requirement yet, as a message for
     * the user, or null when it does.
     */
    public function unmetReason(WorkOrder $workOrder, User $user): ?string;
}
