<?php

namespace App\States\WorkOrder;

use App\Enums\Permission;

/**
 * The Direktur approved the BAST; an Admin WO closes the work order.
 */
class BastDisetujui extends WorkOrderStatus
{
    public static string $name = 'bast_disetujui';

    public function label(): string
    {
        return 'BAST Disetujui';
    }

    /**
     * An outline of the success tone, so it is not taken for Closed or
     * Lunas (docs/DESIGN.md › Status Work Order).
     */
    public function tone(): string
    {
        return 'approved';
    }

    public function isActive(): bool
    {
        return true;
    }

    public static function transitions(): array
    {
        return [
            new WorkOrderTransition(Closed::class, Permission::WorkOrdersClose, 'Tutup work order'),
        ];
    }

    public function requiresNumber(): bool
    {
        return true;
    }

    public function waitingMessage(): string
    {
        return __('Menunggu Admin WO menutup work order.');
    }
}
