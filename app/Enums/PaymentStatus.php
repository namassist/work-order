<?php

namespace App\Enums;

use App\Models\WorkOrder;
use App\Models\WorkOrderInvoice;
use App\States\WorkOrder\Closed;
use Illuminate\Database\Eloquent\Builder;

/**
 * The payment track of a closed work order (FLOW.md §10): Belum ditagih →
 * Ditagih → Lunas. It is not stored: it follows from the work order's
 * invoice (none, unpaid, paid), which exists only on closed work orders
 * (BillWorkOrder refuses any other). See WorkOrder::paymentStatus().
 */
enum PaymentStatus: string
{
    case BelumDitagih = 'belum_ditagih';
    case Ditagih = 'ditagih';
    case Lunas = 'lunas';

    public function label(): string
    {
        return match ($this) {
            self::BelumDitagih => 'Belum ditagih',
            self::Ditagih => 'Ditagih',
            self::Lunas => 'Lunas',
        };
    }

    /**
     * The badge colour token from docs/DESIGN.md › Status pembayaran.
     */
    public function tone(): string
    {
        return match ($this) {
            self::BelumDitagih => 'secondary',
            self::Ditagih => 'warning',
            self::Lunas => 'success',
        };
    }

    /**
     * The payment status an invoice (or its absence) stands for.
     */
    public static function of(?WorkOrderInvoice $invoice): self
    {
        return match (true) {
            ! $invoice instanceof WorkOrderInvoice => self::BelumDitagih,
            $invoice->isPaid() => self::Lunas,
            default => self::Ditagih,
        };
    }

    /**
     * Limits the query to closed work orders in this payment status.
     *
     * @param  Builder<WorkOrder>  $query
     */
    public function constrain(Builder $query): void
    {
        $query->where('status', Closed::getMorphClass());

        match ($this) {
            self::BelumDitagih => $query->whereDoesntHave('invoice'),
            self::Ditagih => $query->whereHas('invoice', fn (Builder $invoice) => $invoice->whereNull('paid_on')),
            self::Lunas => $query->whereHas('invoice', fn (Builder $invoice) => $invoice->whereNotNull('paid_on')),
        };
    }

    /**
     * @return array{value: string, label: string, tone: string}
     */
    public function toOption(): array
    {
        return ['value' => $this->value, 'label' => $this->label(), 'tone' => $this->tone()];
    }

    /**
     * Every payment status, in track order.
     *
     * @return list<array{value: string, label: string, tone: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => $status->toOption(), self::cases());
    }
}
