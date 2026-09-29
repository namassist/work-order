<?php

namespace App\States\WorkOrder;

use App\Actions\WorkOrders\Transitions\TransitionEffect;
use App\Actions\WorkOrders\Transitions\TransitionRequirement;
use App\Enums\Permission;

/**
 * One allowed status change (FLOW.md §5.1, §5.2), declared by the status it
 * leaves in WorkOrderStatus::transitions(): where it goes, the permission
 * that makes it, and how its button and note look. The same destination may
 * be reached by different people from different statuses (Pelaksanaan is
 * approved by Lead Operational and returned to by Rental; Dibatalkan is
 * entered by Admin WO or Lead Operational), so all of this belongs to the
 * transition, not to the destination.
 *
 * Requirements run under the work order's lock before the status changes
 * and refuse it with a validation message; effects run after it, in the same
 * transaction (TransitionWorkOrder). They are the extension points of the
 * later steps: the daily report (step 4) and the BAST (step 5).
 */
final readonly class WorkOrderTransition
{
    /**
     * @param  class-string<WorkOrderStatus>  $to
     * @param  list<class-string<TransitionRequirement>>  $requirements
     * @param  list<class-string<TransitionEffect>>  $effects
     */
    public function __construct(
        public string $to,
        public Permission $permission,
        public string $label,
        public bool $requiresNote = false,
        public string $noteLabel = 'Catatan',
        public bool $isDestructive = false,
        public array $requirements = [],
        public array $effects = [],
    ) {}

    /**
     * Ending the work order with a reason (FLOW.md §5.2). Cancelling is
     * never whose turn it is, so it does not count for waitsOn().
     */
    public static function cancel(Permission $permission): self
    {
        return new self(Dibatalkan::class, $permission, 'Batalkan', requiresNote: true, noteLabel: 'Alasan pembatalan', isDestructive: true);
    }

    /**
     * The stored name of the destination status.
     */
    public function toName(): string
    {
        return $this->to::getMorphClass();
    }

    public function isCancellation(): bool
    {
        return $this->to === Dibatalkan::class;
    }
}
