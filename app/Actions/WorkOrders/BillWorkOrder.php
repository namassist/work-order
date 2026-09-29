<?php

namespace App\Actions\WorkOrders;

use App\Actions\Attachments\AddAttachment;
use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Enums\PaymentStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderInvoice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bills a closed work order (payment track Belum ditagih → Ditagih, FLOW.md
 * §10): writes the invoice and stores its files (at least one), in one
 * transaction with the work order locked. The work order's status stays
 * Closed. A refused file or a taken invoice number leaves no invoice and no
 * stored file.
 */
class BillWorkOrder
{
    use LogsAuditChanges;

    public function __construct(private readonly AddAttachment $addAttachment) {}

    /**
     * @param  array{number: string, invoice_date: string, amount?: string|null, due_date?: string|null}  $invoice
     * @param  list<UploadedFile>  $invoiceFiles
     *
     * @throws InvoiceNotAllowed when the work order is not closed or already billed
     * @throws ValidationException when a file is refused or the invoice number is taken
     */
    public function handle(WorkOrder $workOrder, User $user, array $invoice, array $invoiceFiles): WorkOrder
    {
        try {
            return DB::transaction(function () use ($workOrder, $user, $invoice, $invoiceFiles): WorkOrder {
                $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

                if ($locked->paymentStatus() !== PaymentStatus::BelumDitagih) {
                    throw InvoiceNotAllowed::notBillable();
                }

                $record = new WorkOrderInvoice(array_intersect_key($invoice, array_flip(WorkOrderInvoice::FORM_FIELDS)));
                $record->workOrder()->associate($locked);
                $record->issuer()->associate($user);
                $record->save();
                $locked->setRelation('invoice', $record);

                $collection = $locked->attachmentCollections()[WorkOrder::INVOICE];

                foreach ($invoiceFiles as $file) {
                    $this->addAttachment->handle($locked, $collection, $file, $user, 'invoice_files');
                }

                if ($locked->media()->where('collection_name', WorkOrder::INVOICE)->count() < $collection->minFiles) {
                    throw ValidationException::withMessages(['invoice_files' => __('Invoice harus memiliki minimal satu berkas.')]);
                }

                // Billing is activity on the work order, although its status stays Closed.
                $locked->touch();

                $this->logAuditChange($locked, AuditEvent::InvoiceIssued, [], $record->refresh()->auditValues());

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            // Another invoice took the number between validation and saving.
            throw ValidationException::withMessages(['invoice_number' => __('validation.unique', ['attribute' => __('validation.attributes.invoice_number')])]);
        }
    }
}
