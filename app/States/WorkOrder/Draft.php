<?php

namespace App\States\WorkOrder;

use App\Enums\Permission;
use App\Models\WorkOrder;

/**
 * Entered by an Admin WO and not submitted yet: no number, visible only to
 * Admin WO users (FLOW.md §6), deleted rather than cancelled.
 */
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

    public function isActive(): bool
    {
        return false;
    }

    public static function transitions(): array
    {
        return [
            new WorkOrderTransition(Diajukan::class, Permission::WorkOrdersSubmit, 'Ajukan'),
            WorkOrderTransition::cancel(Permission::WorkOrdersCancel),
        ];
    }

    public function isEditable(): bool
    {
        return true;
    }

    public function isDeletable(): bool
    {
        return true;
    }

    public function attachmentPermissions(): array
    {
        return [WorkOrder::DOCUMENTS => Permission::WorkOrdersUpdate];
    }

    public function waitingMessage(): string
    {
        return __('Menunggu Admin WO mengajukan.');
    }
}
