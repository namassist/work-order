<?php

namespace App\States\WorkOrder;

use App\Enums\WorkOrderSide;
use App\Models\WorkOrder;

/**
 * Accepted and being carried out by the target department, which may add
 * documents meanwhile.
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

    public function actionLabel(): string
    {
        return 'Kerjakan';
    }

    public function countsAsOverdueWhenLate(): bool
    {
        return true;
    }

    public function requiresTargetDepartment(): bool
    {
        return true;
    }

    public function performedBy(): WorkOrderSide
    {
        return WorkOrderSide::Executor;
    }

    public function attachmentSide(): WorkOrderSide
    {
        return WorkOrderSide::Executor;
    }

    public function waitsOn(): WorkOrderSide
    {
        return WorkOrderSide::Executor;
    }

    public function waitingMessage(WorkOrder $workOrder): string
    {
        return __('Sedang dikerjakan oleh :department.', ['department' => $workOrder->targetDepartment?->code]);
    }
}
