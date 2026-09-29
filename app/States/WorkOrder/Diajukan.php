<?php

namespace App\States\WorkOrder;

use App\Enums\Permission;
use App\Enums\WorkOrderDeadline;

/**
 * Submitted by an Admin WO, numbered on the first submission, and waiting
 * for Lead Operational to approve or reject it.
 */
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

    public function isActive(): bool
    {
        return true;
    }

    public static function transitions(): array
    {
        return [
            new WorkOrderTransition(Pelaksanaan::class, Permission::WorkOrdersApprove, 'Setujui'),
            new WorkOrderTransition(Ditolak::class, Permission::WorkOrdersApprove, 'Tolak', requiresNote: true, noteLabel: 'Alasan penolakan', isDestructive: true),
            WorkOrderTransition::cancel(Permission::WorkOrdersCancel),
        ];
    }

    public function assignsNumber(): bool
    {
        return true;
    }

    public function deadline(): WorkOrderDeadline
    {
        return WorkOrderDeadline::TargetDate;
    }

    public function requiresNumber(): bool
    {
        return true;
    }

    public function waitingMessage(): string
    {
        return __('Menunggu persetujuan Lead Operational.');
    }
}
