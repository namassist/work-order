<?php

namespace App\Actions\WorkOrders;

use App\Actions\Attachments\AddAttachment;
use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderInvoice;
use App\States\WorkOrder\Penagihan;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

/**
 * Invoices a finished work order (Dikerjakan → Penagihan, FLOW.md §8): writes
 * the invoice, stores its files (at least one invoice file, optional BAST),
 * and moves the work order, all in one transaction. A refused file or a
 * taken invoice number leaves no invoice, no status change, and no stored
 * file.
 */
class BillWorkOrder
{
    use LogsAuditChanges;

    public function __construct(
        private readonly TransitionWorkOrder $transition,
        private readonly AddAttachment $addAttachment,
    ) {}

    /**
     * @param  array{number: string, invoice_date: string, amount?: string|null, due_date?: string|null}  $invoice
     * @param  list<UploadedFile>  $invoiceFiles
     * @param  list<UploadedFile>  $bastFiles
     *
     * @throws CouldNotPerformTransition when the work order is no longer Dikerjakan
     * @throws ValidationException when a file is refused or the invoice number is taken
     */
    public function handle(WorkOrder $workOrder, User $user, array $invoice, array $invoiceFiles, array $bastFiles = []): WorkOrder
    {
        try {
            return DB::transaction(function () use ($workOrder, $user, $invoice, $invoiceFiles, $bastFiles): WorkOrder {
                $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

                if (! $locked->status->canTransitionTo(Penagihan::class)) {
                    throw CouldNotPerformTransition::notFound($locked->status->getValue(), Penagihan::$name, $locked);
                }

                $record = new WorkOrderInvoice(array_intersect_key($invoice, array_flip(WorkOrderInvoice::FORM_FIELDS)));
                $record->workOrder()->associate($locked);
                $record->issuer()->associate($user);
                $record->save();

                $collections = $locked->attachmentCollections();

                foreach ($invoiceFiles as $file) {
                    $this->addAttachment->handle($locked, $collections[WorkOrder::INVOICE], $file, $user, 'invoice_files');
                }

                foreach ($bastFiles as $file) {
                    $this->addAttachment->handle($locked, $collections[WorkOrder::BAST], $file, $user, 'bast_files');
                }

                $billed = $this->transition->handle($locked, Penagihan::$name, $user);

                $this->logAuditChange($billed, AuditEvent::InvoiceIssued, [], $record->refresh()->auditValues());

                return $billed;
            });
        } catch (UniqueConstraintViolationException) {
            // Another invoice took the number between validation and saving.
            throw ValidationException::withMessages(['invoice_number' => __('validation.unique', ['attribute' => __('validation.attributes.invoice_number')])]);
        }
    }
}
