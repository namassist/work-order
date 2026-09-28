<?php

namespace App\States\WorkOrder;

use App\Enums\WorkOrderSide;

/**
 * The invoice was paid and the finance side confirmed it with a payment
 * date (FLOW.md §8). Final: comments and attachments are read-only and
 * nothing moves it further.
 */
class Selesai extends WorkOrderStatus
{
    public static string $name = 'selesai';

    public function label(): string
    {
        return 'Selesai';
    }

    public function tone(): string
    {
        return 'success';
    }

    public function isActive(): bool
    {
        return false;
    }

    public function actionLabel(): string
    {
        return 'Konfirmasi pembayaran';
    }

    public function transitionForm(): string
    {
        return 'payment';
    }

    public function acceptsComments(): bool
    {
        return false;
    }

    public function requiresTargetDepartment(): bool
    {
        return true;
    }

    public function performedBy(): WorkOrderSide
    {
        return WorkOrderSide::Finance;
    }
}
