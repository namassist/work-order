<?php

namespace App\States\WorkOrder;

use App\Enums\WorkOrderDeadline;
use App\Enums\WorkOrderSide;
use App\Models\WorkOrder;

/**
 * The work is done and the target department has invoiced it (FLOW.md §8):
 * the invoice (number, date, optional amount and due date, at least one
 * invoice file, optional BAST) is entered with the transition through
 * BillWorkOrder. While it waits for payment the target department may
 * correct the invoice and its files (CorrectInvoice), and the finance side
 * adds proof of payment and confirms it (Selesai).
 */
class Penagihan extends WorkOrderStatus
{
    public static string $name = 'penagihan';

    public function label(): string
    {
        return 'Penagihan';
    }

    public function tone(): string
    {
        return 'billing';
    }

    public function isActive(): bool
    {
        return true;
    }

    public function actionLabel(): string
    {
        return 'Tagihkan';
    }

    public function transitionForm(): string
    {
        return 'invoice';
    }

    public function deadline(): WorkOrderDeadline
    {
        return WorkOrderDeadline::PaymentDueDate;
    }

    public function requiresNumber(): bool
    {
        return true;
    }

    public function performedBy(): WorkOrderSide
    {
        return WorkOrderSide::Executor;
    }

    /**
     * The finance side adds proof of payment. The invoice and BAST files
     * change only through CorrectInvoice, which records who corrected them
     * (segregation of duties, FLOW.md §8).
     */
    public function attachmentSides(): array
    {
        return [WorkOrder::PAYMENT_PROOF => WorkOrderSide::Finance];
    }

    public function waitsOn(): WorkOrderSide
    {
        return WorkOrderSide::Finance;
    }

    public function waitingMessage(WorkOrder $workOrder): string
    {
        return 'Menunggu konfirmasi pembayaran oleh Finance.';
    }
}
