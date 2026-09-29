<?php

namespace App\Concerns;

use App\Models\WorkOrder;
use App\Models\WorkOrderInvoice;
use App\Support\DisplayDate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rules for the invoice form (FLOW.md §10), shared by issuing an invoice
 * (BillWorkOrder) and correcting it (CorrectInvoice). Dates are calendar
 * days (Y-m-d) compared with today in the display timezone (WITA).
 */
trait WorkOrderInvoiceRules
{
    /**
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    protected function invoiceFieldRules(?WorkOrderInvoice $ignore = null): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:'.WorkOrderInvoice::MAX_NUMBER_LENGTH, $this->uniqueInvoiceNumber($ignore)],
            'invoice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.DisplayDate::today()],
            // Rupiah with at most two decimals, a dot as the separator.
            'amount' => ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'numeric', 'gt:0'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
        ];
    }

    /**
     * Rules for a list of uploads to one of the work order's collections.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function invoiceFileRules(WorkOrder $workOrder, string $collection, string $input, bool $required): array
    {
        $rules = $workOrder->attachmentCollections()[$collection];

        return [
            $input => [$required ? 'required' : 'nullable', 'array', ...($required ? ['min:'.$rules->minFiles] : []), 'max:'.$rules->maxFiles],
            $input.'.*' => $rules->fileRules(),
        ];
    }

    /**
     * The invoice fields as the model's attributes.
     *
     * @return array{number: string, invoice_date: string, amount: string|null, due_date: string|null}
     */
    public function invoiceAttributes(): array
    {
        return [
            'number' => trim($this->string('invoice_number')->toString()),
            'invoice_date' => $this->string('invoice_date')->toString(),
            'amount' => $this->filled('amount') ? $this->string('amount')->toString() : null,
            'due_date' => $this->filled('due_date') ? $this->string('due_date')->toString() : null,
        ];
    }

    /**
     * Invoice numbers are unique whatever their casing, like the index
     * work_order_invoices_number_lower_unique.
     */
    private function uniqueInvoiceNumber(?WorkOrderInvoice $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            $taken = WorkOrderInvoice::query()
                ->withNumber((string) $value)
                ->when($ignore, fn ($query, WorkOrderInvoice $invoice) => $query->whereKeyNot($invoice->getKey()))
                ->exists();

            if ($taken) {
                $fail('validation.unique')->translate();
            }
        };
    }
}
