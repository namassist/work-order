<?php

namespace App\Actions\WorkOrders;

use App\Actions\Attachments\AddAttachment;
use App\Actions\Attachments\RemoveAttachment;
use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderInvoice;
use App\States\WorkOrder\Penagihan;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects the invoice of a work order that waits for payment (FLOW.md §8):
 * its fields, and its invoice and BAST files (added and removed), in one
 * transaction. The correction is logged on the work order with the fields
 * before and after, and its user becomes the invoice's corrector, who may
 * therefore not confirm its payment.
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
     * @param  list<UploadedFile>  $bastFiles  files to add to the BAST
     * @param  list<string>  $removeMediaUuids  invoice or BAST files to remove
     *
     * @throws InvoiceNotAllowed when the work order is no longer in Penagihan
     * @throws ValidationException when a file is refused, the invoice would have no file, or the number is taken
     */
    public function handle(WorkOrder $workOrder, User $user, array $invoice, array $invoiceFiles = [], array $bastFiles = [], array $removeMediaUuids = []): WorkOrderInvoice
    {
        try {
            return DB::transaction(function () use ($workOrder, $user, $invoice, $invoiceFiles, $bastFiles, $removeMediaUuids): WorkOrderInvoice {
                $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
                $record = $locked->invoice()->lockForUpdate()->first();

                if (! $locked->status->equals(Penagihan::class) || $record === null) {
                    throw InvoiceNotAllowed::notCorrectable();
                }

                $before = $record->auditValues();
                $record->fill(array_intersect_key($invoice, array_flip(WorkOrderInvoice::FORM_FIELDS)));

                $removed = $locked->media()
                    ->whereIn('collection_name', [WorkOrder::INVOICE, WorkOrder::BAST])
                    ->whereIn('uuid', $removeMediaUuids)
                    ->get();

                if (! $record->isDirty() && $removed->isEmpty() && $invoiceFiles === [] && $bastFiles === []) {
                    return $record;
                }

                $record->corrector()->associate($user);
                $record->save();

                $collections = $locked->attachmentCollections();

                foreach ($invoiceFiles as $file) {
                    $this->addAttachment->handle($locked, $collections[WorkOrder::INVOICE], $file, $user, 'invoice_files');
                }

                foreach ($bastFiles as $file) {
                    $this->addAttachment->handle($locked, $collections[WorkOrder::BAST], $file, $user, 'bast_files');
                }

                $remainingInvoiceFiles = $locked->media()->where('collection_name', WorkOrder::INVOICE)->count()
                    - $removed->where('collection_name', WorkOrder::INVOICE)->count();

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
