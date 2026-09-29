<?php

namespace App\States\WorkOrder;

use App\Enums\Permission;

/**
 * The BAST was submitted and waits for the Direktur, who only approves it
 * (FLOW.md §5.1). From here on the work order is never cancelled (§5.2).
 */
class ApprovalBast extends WorkOrderStatus
{
    public static string $name = 'approval_bast';

    public function label(): string
    {
        return 'Approval BAST';
    }

    public function tone(): string
    {
        return 'approval';
    }

    public function isActive(): bool
    {
        return true;
    }

    public static function transitions(): array
    {
        return [
            new WorkOrderTransition(BastDisetujui::class, Permission::WorkOrdersApproveBast, 'Setujui BAST'),
        ];
    }

    public function requiresNumber(): bool
    {
        return true;
    }

    public function waitingMessage(): string
    {
        return __('Menunggu persetujuan BAST oleh Direktur.');
    }
}
