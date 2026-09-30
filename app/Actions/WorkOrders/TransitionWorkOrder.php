<?php

namespace App\Actions\WorkOrders;

use App\Actions\WorkOrders\Transitions\TransitionEffect;
use App\Actions\WorkOrders\Transitions\TransitionRequirement;
use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\WorkOrderNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

/**
 * Moves a work order to another status, in one transaction with the work
 * order locked: checks the transition's requirements, assigns the number
 * when the new status calls for one, changes the status, writes the status
 * history row, logs the change to the audit trail, and runs the
 * transition's effects (WorkOrderTransition).
 */
class TransitionWorkOrder
{
    use LogsAuditChanges;

    public function __construct(private readonly WorkOrderNumberGenerator $numbers) {}

    /**
     * @throws CouldNotPerformTransition when the current status does not allow the transition, or not for this user
     * @throws ValidationException when the transition needs a note and has none, or a requirement is not met
     */
    public function handle(WorkOrder $workOrder, string $to, User $user, ?string $note = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $to, $user, $note): WorkOrder {
            // Re-read under lock so two users acting at once see each other's change.
            $locked = WorkOrder::query()->with('requesterDepartment')->lockForUpdate()->findOrFail($workOrder->id);

            $from = $locked->status->getValue();
            $oldNumber = $locked->number;
            $transition = $locked->status->transitionFor($to);

            // The same destination may need another permission from another status
            // (cancel before execution, cancel-execution during it), so the policy's
            // answer for the status the page loaded is checked again here.
            if ($transition === null || $user->isClient() || ! $user->checkPermissionTo($transition->permission->value)) {
                throw CouldNotPerformTransition::notFound($from, $to, $locked);
            }

            if ($transition->requiresNote && blank($note)) {
                throw ValidationException::withMessages(['note' => __('validation.required', ['attribute' => __('validation.attributes.note')])]);
            }

            foreach ($transition->requirements as $requirement) {
                /** @var TransitionRequirement $check */
                $check = app($requirement);
                $reason = $check->unmetReason($locked, $user);

                if ($reason !== null) {
                    throw ValidationException::withMessages(['status' => $reason]);
                }
            }

            $target = new $transition->to($locked);

            if ($target->assignsNumber() && $locked->number === null) {
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

            foreach ($transition->effects as $effect) {
                /** @var TransitionEffect $run */
                $run = app($effect);
                $run->handle($locked, $user);
            }

            return $locked;
        });
    }
}
