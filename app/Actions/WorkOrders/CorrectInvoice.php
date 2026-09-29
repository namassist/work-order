<?php

namespace App\Actions\WorkOrders;

use App\Actions\Attachments\AddAttachment;
use App\Actions\Attachments\RemoveAttachment;
use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Enums\PaymentStatus;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderInvoice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects the invoice of a closed work order that waits for payment
 * (Ditagih, FLOW.md §10): its fields and its files (added and removed), in
 * one transaction. The correction is logged on the work order with the
 * fields before and after, and its user becomes the invoice's corrector,
 * who may therefore not confirm its payment when segregation of duties is on.
 */
class CorrectInvoice
{
    use LogsAuditChanges;

    public function __construct(
        private readonly AddAttachment $addAttachment,
        private readonly RemoveAttachment $removeAttachment,
    ) {}

    /**
     * @param  array{number: string, invoice_date: string, amount?: string|null, due_date?: string|null}  $invoice
     * @param  list<UploadedFile>  $invoiceFiles  files to add to the invoice
     * @param  list<string>  $removeMediaUuids  invoice files to remove
     *
     * @throws InvoiceNotAllowed when the payment is no longer Ditagih
     * @throws ValidationException when a file is refused, the invoice would have no file, or the number is taken
     */
    public function handle(WorkOrder $workOrder, User $user, array $invoice, array $invoiceFiles = [], array $removeMediaUuids = []): WorkOrderInvoice
    {
        try {
            return DB::transaction(function () use ($workOrder, $user, $invoice, $invoiceFiles, $removeMediaUuids): WorkOrderInvoice {
                $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
                $record = $locked->invoice()->lockForUpdate()->first();
                $locked->setRelation('invoice', $record);

                if ($record === null || $locked->paymentStatus() !== PaymentStatus::Ditagih) {
                    throw InvoiceNotAllowed::notCorrectable();
                }

                $before = $record->auditValues();
                $record->fill(array_intersect_key($invoice, array_flip(WorkOrderInvoice::FORM_FIELDS)));

                $removed = $locked->media()
                    ->where('collection_name', WorkOrder::INVOICE)
                    ->whereIn('uuid', $removeMediaUuids)
                    ->get();

                if (! $record->isDirty() && $removed->isEmpty() && $invoiceFiles === []) {
                    return $record;
                }

                $record->corrector()->associate($user);
                $record->save();

                $collections = $locked->attachmentCollections();

                foreach ($invoiceFiles as $file) {
                    $this->addAttachment->handle($locked, $collections[WorkOrder::INVOICE], $file, $user, 'invoice_files');
                }

                $remainingInvoiceFiles = $locked->media()->where('collection_name', WorkOrder::INVOICE)->count() - $removed->count();

                if ($remainingInvoiceFiles < $collections[WorkOrder::INVOICE]->minFiles) {
                    throw ValidationException::withMessages(['invoice_files' => __('Invoice harus memiliki minimal satu berkas.')]);
                }

                // Last: deleting a file cannot be rolled back.
                foreach ($removed as $media) {
                    /** @var Media $media */
                    $this->removeAttachment->handle($locked, $media);
                }

                $this->logAuditChange($locked, AuditEvent::InvoiceCorrected, $before, $record->refresh()->auditValues());

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['invoice_number' => __('validation.unique', ['attribute' => __('validation.attributes.invoice_number')])]);
        }
    }
}
