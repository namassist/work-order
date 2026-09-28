<?php

namespace App\States\WorkOrder;

use App\Enums\WorkOrderSide;

/**
 * Ended by the requester side with a reason, from Draft, Diajukan, or
 * Ditolak. Final: nothing is left to do, so it is shown muted.
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

    public function actionLabel(): string
    {
        return 'Batalkan';
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
        return 'Alasan pembatalan';
    }

    public function acceptsComments(): bool
    {
        return false;
    }

    public function performedBy(): WorkOrderSide
    {
        return WorkOrderSide::Requester;
    }
}
