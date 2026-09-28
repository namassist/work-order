<?php

namespace App\States\WorkOrder;

use App\Enums\WorkOrderSide;
use App\Models\WorkOrder;

class Draft extends WorkOrderStatus
{
    public static string $name = 'draft';

    public function label(): string
    {
        return 'Draft';
    }

    public function tone(): string
    {
        return 'secondary';
    }

    public function isEditable(): bool
    {
        return true;
    }

    public function isDeletable(): bool
    {
        return true;
    }

    public function attachmentSide(): WorkOrderSide
    {
        return WorkOrderSide::Requester;
    }

    public function waitsOn(): WorkOrderSide
    {
        return WorkOrderSide::Requester;
    }

    public function waitingMessage(WorkOrder $workOrder): string
    {
        return 'Menunggu pemohon mengajukan.';
    }
}
