<?php

namespace App\States\WorkOrder;

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

    public function actionLabel(): string
    {
        return 'Ajukan';
    }

    public function assignsNumber(): bool
    {
        return true;
    }
}
