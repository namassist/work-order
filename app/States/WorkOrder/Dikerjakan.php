<?php

namespace App\States\WorkOrder;

use App\Enums\WorkOrderDeadline;
use App\Enums\WorkOrderSide;
use App\Models\WorkOrder;

/**
 * Accepted and being carried out by the target department, which may add
 * documents and the BAST meanwhile, then invoices it (Penagihan).
 */
class Dikerjakan extends WorkOrderStatus
{
    public static string $name = 'dikerjakan';

    public function label(): string
    {
        return 'Dikerjakan';
    }

    public function tone(): string
    {
        return 'info';
    }

    public function isActive(): bool
    {
        return true;
    }

    public function actionLabel(): string
    {
        return 'Kerjakan';
    }

    public function deadline(): WorkOrderDeadline
    {
        return WorkOrderDeadline::TargetDate;
    }

    public function requiresNumber(): bool
    {
        return true;
    }

    public function performedBy(): WorkOrderSide
    {
        return WorkOrderSide::Executor;
    }

    /**
     * The target department adds documents and the BAST while it works.
     */
    public function attachmentSides(): array
    {
        return [
            WorkOrder::DOCUMENTS => WorkOrderSide::Executor,
            WorkOrder::BAST => WorkOrderSide::Executor,
        ];
    }

    public function waitsOn(): WorkOrderSide
    {
        return WorkOrderSide::Executor;
    }

    public function waitingMessage(WorkOrder $workOrder): string
    {
        return __('Sedang dikerjakan.');
    }
}
