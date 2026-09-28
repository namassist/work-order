<?php

namespace App\Http\Resources;

use App\Models\WorkOrderInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A work order's invoice for its detail page. Everyone who may view the work
 * order sees it, IC users included (they pay it); of the executor company's
 * users it gives only their names.
 *
 * @property WorkOrderInvoice $resource
 */
class WorkOrderInvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invoice = $this->resource;

        return [
            'number' => $invoice->number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'amount' => $invoice->amount,
            'due_date' => $invoice->due_date?->toDateString(),
            'paid_on' => $invoice->paid_on?->toDateString(),
            'is_overdue' => $invoice->workOrder->isOverdue(),
            'issued_by' => ['name' => $invoice->issuer->name],
            'corrected_by' => $invoice->corrector ? ['name' => $invoice->corrector->name] : null,
            'paid_by' => $invoice->payer ? ['name' => $invoice->payer->name] : null,
        ];
    }
}
