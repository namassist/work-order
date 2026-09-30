<?php

namespace App\Actions\WorkOrders\Transitions;

use App\Models\BastTemplateVersion;
use App\Models\User;
use App\Models\WorkOrder;

/**
 * Review Dokumen → Approval BAST generates the BAST from the active
 * template version (FLOW.md §8), so it needs one.
 */
class EnsureActiveBastTemplate implements TransitionRequirement
{
    public function unmetReason(WorkOrder $workOrder, User $user): ?string
    {
        if (BastTemplateVersion::active() instanceof BastTemplateVersion) {
            return null;
        }

        return __('Belum ada template BAST yang aktif. Minta admin menerbitkan template BAST.');
    }
}
