<?php

namespace App\Actions\WorkOrders;

use App\Actions\Attachments\AddAttachment;
use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\Penagihan;
use App\States\WorkOrder\Selesai;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

/**
 * Confirms that a work order's invoice was paid (Penagihan → Selesai,
 * FLOW.md §8): records the payment date and its confirmer, stores optional
 * proof of payment, and closes the work order, in one transaction. With the
 * work order locked it refuses a user who issued or last corrected the
 * invoice (segregation of duties) and a payment date before the invoice
 * date (the invoice may have been corrected since the form was validated).
 */
class ConfirmWorkOrderPayment
{
    use LogsAuditChanges;

    public function __construct(
        private readonly TransitionWorkOrder $transition,
        private readonly AddAttachment $addAttachment,
    ) {}

    /**
     * @param  string  $paidOn  Y-m-d, not in the future (validated by the request)
     * @param  list<UploadedFile>  $proofFiles
     *
     * @throws CouldNotPerformTransition when the work order is no longer in Penagihan
     * @throws InvoiceNotAllowed when the user issued or last corrected the invoice
     * @throws ValidationException when the payment date is before the invoice date or a file is refused
     */
    public function handle(WorkOrder $workOrder, User $user, string $paidOn, array $proofFiles = []): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $user, $paidOn, $proofFiles): WorkOrder {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $invoice = $locked->invoice()->lockForUpdate()->first();

            if (! $locked->status->equals(Penagihan::class) || $invoice === null) {
                throw CouldNotPerformTransition::notFound($locked->status->getValue(), Selesai::$name, $locked);
            }

            if ($invoice->wasPreparedBy($user)) {
                throw InvoiceNotAllowed::preparedByPayer();
            }

            if ($paidOn < $invoice->invoice_date->toDateString()) {
                throw ValidationException::withMessages(['paid_on' => __('validation.after_or_equal', [
                    'attribute' => __('validation.attributes.paid_on'),
                    'date' => __('validation.attributes.invoice_date'),
                ])]);
            }

            $invoice->paid_on = CarbonImmutable::parse($paidOn);
            $invoice->payer()->associate($user);
            $invoice->save();

            $collection = $locked->attachmentCollections()[WorkOrder::PAYMENT_PROOF];

            foreach ($proofFiles as $file) {
                $this->addAttachment->handle($locked, $collection, $file, $user, 'proof_files');
            }

            $paid = $this->transition->handle($locked, Selesai::$name, $user);

            $this->logAuditChange($paid, AuditEvent::PaymentConfirmed, [], ['tanggal_bayar' => $paidOn]);

            return $paid;
        });
    }
}
