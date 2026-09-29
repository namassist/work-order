<?php

namespace App\States\WorkOrder;

/**
 * Ended with a reason (FLOW.md §5.2): by an Admin WO before execution, by
 * Lead Operational during Pelaksanaan or Review Dokumen. Final: nothing is
 * left to do, so it is shown muted.
 */
class Dibatalkan extends WorkOrderStatus
{
    public static string $name = 'dibatalkan';

    public function label(): string
    {
        return 'Dibatalkan';
    }

    public function tone(): string
    {
        return 'muted';
    }

    public function isActive(): bool
    {
        return false;
    }

    public function acceptsComments(): bool
    {
        return false;
    }
}
