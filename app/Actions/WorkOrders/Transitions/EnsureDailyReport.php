<?php

namespace App\Actions\WorkOrders\Transitions;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\DisplayDate;
use Carbon\CarbonInterface;

/**
 * Pelaksanaan → Review Dokumen needs a daily report (FLOW.md §5.1, §7).
 * After Rental returned the work order for revision, a report from before
 * that return does not count: one must be created or edited after it
 * (its updated_at, which every edit bumps).
 */
class EnsureDailyReport implements TransitionRequirement
{
    public function unmetReason(WorkOrder $workOrder, User $user): ?string
    {
        $returnedAt = $workOrder->lastReturnedForRevisionAt();
        $reports = $workOrder->dailyReports();

        if (! $returnedAt instanceof CarbonInterface) {
            if ($reports->exists()) {
                return null;
            }

            return __('Tambahkan minimal satu laporan harian sebelum mengajukan review dokumen.');
        }

        if ($reports->where('updated_at', '>', $returnedAt)->exists()) {
            return null;
        }

        return __('Work order dikembalikan untuk revisi pada :moment. Tambahkan atau perbarui laporan harian setelah itu sebelum mengajukan review dokumen.', [
            'moment' => DisplayDate::dateTime($returnedAt),
        ]);
    }
}
