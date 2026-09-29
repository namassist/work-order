<?php

namespace App\States\WorkOrder;

use App\Enums\WorkOrderSide;
use App\Models\WorkOrder;

/**
 * Rejected by the target department with a reason. The requester side
 * revises it, possibly picking another target department, and resubmits it
 * under the same number, or cancels it.
 */
class Ditolak extends WorkOrderStatus
{
    public static string $name = 'ditolak';

    public function label(): string
    {
        return 'Ditolak';
    }

    public function tone(): string
    {
        return 'destructive';
    }

    public function isActive(): bool
    {
        return true;
    }

    public function actionLabel(): string
    {
        return 'Tolak';
    }

    public function isDestructiveAction(): bool
    {
        return true;
    }

    public function requiresNote(): bool
    {
        return true;
    }

    public function noteLabel(): string
    {
        return 'Alasan penolakan';
    }

    public function isEditable(): bool
    {
        return true;
    }

    public function requiresNumber(): bool
    {
        return true;
    }

    public function performedBy(): WorkOrderSide
    {
        return WorkOrderSide::Executor;
    }

    public function attachmentSides(): array
    {
        return [WorkOrder::DOCUMENTS => WorkOrderSide::Requester];
    }

    public function waitsOn(): WorkOrderSide
    {
        return WorkOrderSide::Requester;
    }

    public function waitingMessage(WorkOrder $workOrder): string
    {
        return 'Menunggu Admin WO merevisi dan mengajukan ulang.';
    }
}
