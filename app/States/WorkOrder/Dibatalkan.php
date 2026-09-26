<?php

namespace App\States\WorkOrder;

class Dibatalkan extends WorkOrderStatus
{
    public static string $name = 'dibatalkan';

    public function label(): string
    {
        return 'Dibatalkan';
    }

    public function tone(): string
    {
        return 'destructive';
    }

    public function actionLabel(): string
    {
        return 'Batalkan';
    }

    public function requiresNote(): bool
    {
        return true;
    }

    public function acceptsComments(): bool
    {
        return false;
    }
}
