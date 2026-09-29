<?php

namespace App\States\WorkOrder;

use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\WorkOrderDeadline;
use App\Models\WorkOrder;

/**
 * Closed by an Admin WO after the BAST was approved: final for the work
 * order flow (FLOW.md §5). Finance then runs the payment track (§10) on it,
 * Belum ditagih → Ditagih → Lunas, which follows from its invoice
 * (WorkOrder::paymentStatus()), so a few properties depend on it: comments
 * stay open until it is paid, proof of payment is added while it is billed,
 * and it is late while billed and past the payment due date.
 */
class Closed extends WorkOrderStatus
{
    public static string $name = 'closed';

    public function label(): string
    {
        return 'Closed';
    }

    public function tone(): string
    {
        return 'success';
    }

    public function isActive(): bool
    {
        return false;
    }

    public function requiresNumber(): bool
    {
        return true;
    }

    public function deadline(): WorkOrderDeadline
    {
        return WorkOrderDeadline::PaymentDueDate;
    }

    /**
     * Finance coordinates billing in the comments, so they stay open until
     * the invoice is paid.
     */
    public function acceptsComments(): bool
    {
        return $this->paymentStatus() !== PaymentStatus::Lunas;
    }

    /**
     * Proof of payment is added while billed. The invoice's own files change
     * only through BillWorkOrder and CorrectInvoice.
     */
    public function attachmentPermissions(): array
    {
        return $this->paymentStatus() === PaymentStatus::Ditagih
            ? [WorkOrder::PAYMENT_PROOF => Permission::WorkOrdersConfirmPayment]
            : [];
    }

    public function waitsOn(): array
    {
        return match ($this->paymentStatus()) {
            PaymentStatus::BelumDitagih => [Permission::WorkOrdersBill],
            PaymentStatus::Ditagih => [Permission::WorkOrdersConfirmPayment],
            PaymentStatus::Lunas => [],
        };
    }

    public function waitingMessage(): ?string
    {
        return match ($this->paymentStatus()) {
            PaymentStatus::BelumDitagih => $this->translated('Menunggu Finance menerbitkan invoice.'),
            PaymentStatus::Ditagih => $this->translated('Menunggu konfirmasi pembayaran oleh Finance.'),
            PaymentStatus::Lunas => null,
        };
    }

    private function translated(string $message): string
    {
        return __($message);
    }

    private function paymentStatus(): PaymentStatus
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $this->getModel();

        return PaymentStatus::of($workOrder->invoice);
    }
}
