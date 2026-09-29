<?php

namespace App\Actions\WorkOrders\Transitions;

use App\Models\User;
use App\Models\WorkOrder;

/**
 * Pelaksanaan → Review Dokumen needs at least one daily report (FLOW.md
 * §5.1, §7). PROVISIONAL: daily reports come with step 4, which fills this
 * in; until then every work order meets it.
 */
class EnsureDailyReport implements TransitionRequirement
{
    public function ensureMet(WorkOrder $workOrder, User $user): void {}
}
