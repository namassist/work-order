<?php

namespace App\States\WorkOrder;

use App\Actions\WorkOrders\Transitions\GenerateBast;
use App\Enums\Permission;
use App\Enums\WorkOrderDeadline;

/**
 * Submitted by PIC Timesheet for document review. Rental returns it to
 * Pelaksanaan with a note when data is missing, or submits the BAST
 * (generated from step 5 on); Lead Operational may still cancel it.
 */
class ReviewDokumen extends WorkOrderStatus
{
    public static string $name = 'review_dokumen';

    public function label(): string
    {
        return 'Review Dokumen';
    }

    public function tone(): string
    {
        return 'review';
    }

    public function isActive(): bool
    {
        return true;
    }

    public static function transitions(): array
    {
        return [
            new WorkOrderTransition(Pelaksanaan::class, Permission::WorkOrdersReview, 'Kembalikan untuk revisi', requiresNote: true, noteLabel: 'Data yang perlu dilengkapi', isDestructive: true),
            new WorkOrderTransition(ApprovalBast::class, Permission::WorkOrdersReview, 'Ajukan BAST', effects: [GenerateBast::class]),
            WorkOrderTransition::cancel(Permission::WorkOrdersCancelExecution),
        ];
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
        return __('Menunggu review dokumen oleh Rental.');
    }
}
