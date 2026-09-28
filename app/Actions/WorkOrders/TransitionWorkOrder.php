<?php

namespace App\Actions\WorkOrders;

use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\Penagihan;
use App\States\WorkOrder\Selesai;
use App\States\WorkOrder\WorkOrderStatus;
use App\Support\WorkOrderNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

/**
 * Moves a work order to another status: assigns its number when the new
 * status calls for one, writes the status history row, and logs the change
 * to the audit trail, all in one transaction.
 */
class TransitionWorkOrder
{
    use LogsAuditChanges;

    public function __construct(private readonly WorkOrderNumberGenerator $numbers) {}

    /**
     * @throws CouldNotPerformTransition when the transition is not allowed from the current status
     * @throws ValidationException when the new status requires a target department and there is none, or an invoice (Penagihan) or its payment (Selesai)
     */
    public function handle(WorkOrder $workOrder, string $to, User $user, ?string $note = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $to, $user, $note): WorkOrder {
            // Re-read under lock so two users acting at once see each other's change.
            $locked = WorkOrder::query()->with('requesterDepartment')->lockForUpdate()->findOrFail($workOrder->id);

            $from = $locked->status->getValue();
            $oldNumber = $locked->number;
            $target = WorkOrderStatus::fromName($to);

            if ($target?->requiresTargetDepartment() && $locked->target_department_id === null) {
                throw ValidationException::withMessages([
                    'status' => __('Pilih departemen tujuan sebelum mengajukan.'),
                ]);
            }

            $this->ensureInvoiced($locked, $to);

            if ($target?->assignsNumber() && $locked->number === null) {
                $locked->number = $this->numbers->next($locked->requesterDepartment->code, now());
            }

            $locked->status->transitionTo($to);

            $locked->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $locked->status->getValue(),
                'user_id' => $user->id,
                'note' => $note,
            ]);

            $this->logAuditChange(
                $locked,
                AuditEvent::StatusChanged,
                ['status' => $from, 'number' => $oldNumber],
                ['status' => $locked->status->getValue(), 'number' => $locked->number],
            );

            return $locked;
        });
    }

    /**
     * A work order enters Penagihan only with an invoice and its file, and
     * Selesai only with the invoice paid (FLOW.md §8). BillWorkOrder and
     * ConfirmWorkOrderPayment write them first; any other caller is refused.
     *
     * @throws ValidationException
     */
    private function ensureInvoiced(WorkOrder $workOrder, string $to): void
    {
        $invoice = $workOrder->invoice;

        $missing = match ($to) {
            Penagihan::$name => $invoice === null
                || $workOrder->media()->where('collection_name', WorkOrder::INVOICE)->count() < $workOrder->attachmentCollections()[WorkOrder::INVOICE]->minFiles,
            Selesai::$name => $invoice?->isPaid() !== true,
            default => false,
        };

        if ($missing) {
            throw ValidationException::withMessages([
                'status' => $to === Penagihan::$name
                    ? __('Lengkapi data invoice dan berkasnya sebelum menagihkan.')
                    : __('Isi tanggal pembayaran sebelum menyelesaikan.'),
            ]);
        }
    }
}
