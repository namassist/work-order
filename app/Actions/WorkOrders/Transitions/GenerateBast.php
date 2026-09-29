<?php

namespace App\Actions\WorkOrders\Transitions;

use App\Models\User;
use App\Models\WorkOrder;

/**
 * Review Dokumen → Approval BAST generates the BAST PDF from the active
 * template and gives it its number (FLOW.md §5.1, §8). PROVISIONAL: BAST
 * templates and generation come with step 5, which fills this in; until
 * then it does nothing.
 */
class GenerateBast implements TransitionEffect
{
    public function handle(WorkOrder $workOrder, User $user): void {}
}
