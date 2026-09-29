<?php

namespace App\States\WorkOrder;

use App\Enums\Permission;
use App\Models\WorkOrder;

/**
 * Rejected by Lead Operational with a reason. An Admin WO revises it and
 * resubmits it under the same number, or cancels it.
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

    public static function transitions(): array
    {
        return [
            new WorkOrderTransition(Diajukan::class, Permission::WorkOrdersSubmit, 'Ajukan ulang'),
            WorkOrderTransition::cancel(Permission::WorkOrdersCancel),
        ];
    }

    public function isEditable(): bool
    {
        return true;
    }

    public function requiresNumber(): bool
    {
        return true;
    }

    public function attachmentPermissions(): array
    {
        return [WorkOrder::DOCUMENTS => Permission::WorkOrdersUpdate];
    }

    public function waitingMessage(): string
    {
        return __('Menunggu Admin WO merevisi dan mengajukan ulang.');
    }
}
