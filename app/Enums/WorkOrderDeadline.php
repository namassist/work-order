<?php

namespace App\Enums;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * The date a work order is late against while in a given status (FLOW.md
 * §7): the target date while it is being handled, the invoice's payment due
 * date while it is being billed. Both are date-only values, compared with
 * today in the display timezone; a work order without the date is never
 * late. See WorkOrderStatus::deadline() and WorkOrder::scopeOverdue().
 */
enum WorkOrderDeadline: string
{
    case TargetDate = 'target_date';
    case PaymentDueDate = 'payment_due_date';

    /**
     * Limits the query to work orders whose date is before $today (Y-m-d).
     *
     * @param  Builder<WorkOrder>  $query
     */
    public function constrainPast(Builder $query, string $today): void
    {
        match ($this) {
            self::TargetDate => $query->whereNotNull('target_date')->where('target_date', '<', $today),
            self::PaymentDueDate => $query->whereHas('invoice', fn (Builder $invoice) => $invoice
                ->whereNull('paid_on')
                ->whereNotNull('due_date')
                ->where('due_date', '<', $today)),
        };
    }

    /**
     * The work order's date (Y-m-d), or null when it has none.
     */
    public function dateOf(WorkOrder $workOrder): ?string
    {
        return match ($this) {
            self::TargetDate => $workOrder->target_date?->toDateString(),
            self::PaymentDueDate => $workOrder->invoice?->paid_on === null ? $workOrder->invoice?->due_date?->toDateString() : null,
        };
    }
}
