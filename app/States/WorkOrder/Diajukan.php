<?php

namespace App\States\WorkOrder;

use App\Enums\WorkOrderDeadline;
use App\Enums\WorkOrderSide;
use App\Models\WorkOrder;

class Diajukan extends WorkOrderStatus
{
    public static string $name = 'diajukan';

    public function label(): string
    {
        return 'Diajukan';
    }

    public function tone(): string
    {
        return 'warning';
    }

    public function isActive(): bool
    {
        return true;
    }

    public function actionLabel(): string
    {
        return 'Ajukan';
    }

    /**
     * A rejected work order is resubmitted under the number it already has.
     */
    public function actionLabelFor(WorkOrder $workOrder): string
    {
        return $workOrder->wasSubmitted() ? 'Ajukan ulang' : $this->actionLabel();
    }

    public function assignsNumber(): bool
    {
        return true;
    }

    public function deadline(): WorkOrderDeadline
    {
        return WorkOrderDeadline::TargetDate;
    }

    public function requiresTargetDepartment(): bool
    {
        return true;
    }

    public function performedBy(): WorkOrderSide
    {
        return WorkOrderSide::Requester;
    }

    public function waitsOn(): WorkOrderSide
    {
        return WorkOrderSide::Executor;
    }

    public function waitingMessage(WorkOrder $workOrder): string
    {
        return __('Menunggu pelaksana :department memproses.', ['department' => $workOrder->targetDepartment?->code]);
    }
}
