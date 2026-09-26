<?php

namespace App\Actions\WorkOrders;

use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\WorkOrderStatus;
use App\Support\WorkOrderNumberGenerator;
use Illuminate\Support\Facades\DB;
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
     */
    public function handle(WorkOrder $workOrder, string $to, User $user, ?string $note = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $to, $user, $note): WorkOrder {
            // Re-read under lock so two users acting at once see each other's change.
            $locked = WorkOrder::query()->with('department')->lockForUpdate()->findOrFail($workOrder->id);

            $from = $locked->status->getValue();
            $oldNumber = $locked->number;
            $target = WorkOrderStatus::fromName($to);

            if ($target?->assignsNumber() && $locked->number === null) {
                $locked->number = $this->numbers->next($locked->department->code, now());
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
}
