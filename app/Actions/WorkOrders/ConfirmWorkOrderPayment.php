<?php

namespace App\Actions\WorkOrders;

use App\Actions\Attachments\AddAttachment;
use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Enums\PaymentStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirms that a closed work order's invoice was paid (payment track
 * Ditagih → Lunas, FLOW.md §10): records the payment date and its confirmer
 * and stores optional proof of payment, in one transaction. With the work
 * order locked it refuses a work order that is not billed, a user who
 * issued or last corrected the invoice when segregation of duties is on
 * (WorkOrderInvoice::segregationBlocks()), and a payment date before the
 * invoice date (the invoice may have been corrected since the form was
 * validated).
 */
class ConfirmWorkOrderPayment
{
    use LogsAuditChanges;

    public function __construct(private readonly AddAttachment $addAttachment) {}

    /**
     * @param  string  $paidOn  Y-m-d, not in the future (validated by the request)
     * @param  list<UploadedFile>  $proofFiles
     *
     * @throws InvoiceNotAllowed when the work order is not billed, or segregation of duties refuses the user
     * @throws ValidationException when the payment date is before the invoice date or a file is refused
     */
    public function handle(WorkOrder $workOrder, User $user, string $paidOn, array $proofFiles = []): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $user, $paidOn, $proofFiles): WorkOrder {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $invoice = $locked->invoice()->lockForUpdate()->first();
            $locked->setRelation('invoice', $invoice);

            if ($invoice === null || $locked->paymentStatus() !== PaymentStatus::Ditagih) {
                throw InvoiceNotAllowed::notPayable();
            }

            if ($invoice->segregationBlocks($user)) {
                throw InvoiceNotAllowed::preparedByPayer();
            }

            if ($paidOn < $invoice->invoice_date->toDateString()) {
                throw ValidationException::withMessages(['paid_on' => __('validation.after_or_equal', [
                    'attribute' => __('validation.attributes.paid_on'),
                    'date' => __('validation.attributes.invoice_date'),
                ])]);
            }

            $collection = $locked->attachmentCollections()[WorkOrder::PAYMENT_PROOF];

            foreach ($proofFiles as $file) {
                $this->addAttachment->handle($locked, $collection, $file, $user, 'proof_files');
            }

            $invoice->paid_on = CarbonImmutable::parse($paidOn);
            $invoice->payer()->associate($user);
            $invoice->save();

            // Payment is activity on the work order, although its status stays Closed.
            $locked->touch();

            $this->logAuditChange($locked, AuditEvent::PaymentConfirmed, [], ['tanggal_bayar' => $paidOn]);

            return $locked;
        });
    }
}
