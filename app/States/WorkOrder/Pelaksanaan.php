<?php

namespace App\States\WorkOrder;

use App\Actions\WorkOrders\Transitions\EnsureDailyReport;
use App\Enums\Permission;
use App\Enums\WorkOrderDeadline;
use App\Models\WorkOrder;

/**
 * Approved by Lead Operational (or returned by Rental for revision) and
 * being carried out. PIC Timesheet adds documents (and, from step 4, the
 * daily reports) and submits it for document review; Lead Operational may
 * cancel it.
 */
class Pelaksanaan extends WorkOrderStatus
{
    public static string $name = 'pelaksanaan';

    public function label(): string
    {
        return 'Pelaksanaan';
    }

    public function tone(): string
    {
        return 'info';
    }

    public function isActive(): bool
    {
        return true;
    }

    public static function transitions(): array
    {
        return [
            new WorkOrderTransition(ReviewDokumen::class, Permission::WorkOrdersSubmitReview, 'Ajukan review dokumen', requirements: [EnsureDailyReport::class]),
            WorkOrderTransition::cancel(Permission::WorkOrdersCancelExecution),
        ];
    }

    public function deadline(): WorkOrderDeadline
    {
        return WorkOrderDeadline::TargetDate;
    }

    public function requiresNumber(): bool
    {
        return true;
    }

    /**
     * PIC Timesheet adds documents while the work is carried out.
     * PROVISIONAL: guarded by work-orders.submit-review until step 4 decides
     * whether daily reports get a permission of their own.
     */
    public function attachmentPermissions(): array
    {
        return [WorkOrder::DOCUMENTS => Permission::WorkOrdersSubmitReview];
    }

    public function waitingMessage(): string
    {
        return __('Sedang dilaksanakan. Menunggu PIC Timesheet mengajukan review dokumen.');
    }
}
