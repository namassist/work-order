<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrderDailyReport;
use Illuminate\Auth\Access\Response;

/**
 * The files of a daily report. Every other report check is on
 * WorkOrderPolicy (report), with the work order as its subject.
 */
class WorkOrderDailyReportPolicy
{
    public function __construct(private readonly WorkOrderPolicy $workOrders) {}

    /**
     * Determine whether the user can open one of the report's files:
     * whoever may view its work order (FLOW.md §6), 404 otherwise. Reports
     * are internal (Unggul) data, so never a client company user, even of
     * the requesting department (the v1 safeguard).
     */
    public function viewAttachment(User $user, WorkOrderDailyReport $report, Media $media): Response
    {
        $workOrder = $report->workOrder;

        if ($user->isClient() || $workOrder->trashed()) {
            return Response::denyAsNotFound();
        }

        return $this->workOrders->view($user, $workOrder);
    }

    /**
     * Report files are added only with the report, through AddDailyReport
     * and UpdateDailyReport.
     */
    public function addAttachment(User $user, WorkOrderDailyReport $report, string $collection): Response
    {
        return Response::denyAsNotFound();
    }

    /**
     * Report files are removed only by editing the report.
     */
    public function deleteAttachment(User $user, WorkOrderDailyReport $report, Media $media): Response
    {
        return Response::deny();
    }
}
