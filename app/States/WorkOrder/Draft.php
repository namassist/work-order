<?php

namespace App\States\WorkOrder;

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
}
