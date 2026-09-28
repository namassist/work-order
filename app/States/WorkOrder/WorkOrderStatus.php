<?php

namespace App\States\WorkOrder;

use App\Enums\WorkOrderSide;
use App\Models\WorkOrder;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Work order status (FLOW.md §5). Every state and allowed transition is
 * defined in this folder and nowhere else; the UI gets them from options()
 * and the available transitions of each WO. Who may perform a transition is
 * the side the destination status names in performedBy().
 *
 * @extends State<WorkOrder>
 */
abstract class WorkOrderStatus extends State
{
    /**
     * The status label shown in badges, lists, and the timeline.
     */
    abstract public function label(): string;

    /**
     * The badge colour token from docs/DESIGN.md: secondary, warning, info,
     * success, destructive, or muted.
     */
    abstract public function tone(): string;

    /**
     * The label of the button that moves a work order into this status.
     */
    public function actionLabel(): string
    {
        return $this->label();
    }

    /**
     * The label of that button for this work order, e.g. "Ajukan ulang"
     * when it was submitted before.
     */
    public function actionLabelFor(WorkOrder $workOrder): string
    {
        return $this->actionLabel();
    }

    /**
     * Whether the button that moves a work order into this status ends or
     * turns it back (reject, cancel), so it gets a destructive style.
     */
    public function isDestructiveAction(): bool
    {
        return false;
    }

    /**
     * Whether moving into this status requires a note.
     */
    public function requiresNote(): bool
    {
        return false;
    }

    /**
     * The label of the note given when moving into this status.
     */
    public function noteLabel(): string
    {
        return 'Catatan';
    }

    /**
     * The side that moves a work order into this status (FLOW.md §5), or
     * null for the initial status, which no transition enters.
     */
    public function performedBy(): ?WorkOrderSide
    {
        return null;
    }

    /**
     * The side whose turn it is while a work order is in this status, or
     * null when nobody has to act (final statuses).
     */
    public function waitsOn(): ?WorkOrderSide
    {
        return null;
    }

    /**
     * Who the work order waits for, shown to users who are not on the side
     * of waitsOn().
     */
    public function waitingMessage(WorkOrder $workOrder): ?string
    {
        return null;
    }

    /**
     * Whether entering this status gives the work order its number.
     */
    public function assignsNumber(): bool
    {
        return false;
    }

    /**
     * Whether the work order's fields may still be edited or it may be deleted.
     */
    public function isEditable(): bool
    {
        return false;
    }

    /**
     * Whether the work order may be deleted: only before it was ever
     * submitted, so a numbered work order is cancelled instead.
     */
    public function isDeletable(): bool
    {
        return false;
    }

    /**
     * The side that may add and remove documents in this status (FLOW.md
     * §5 status properties), or null when nobody may.
     */
    public function attachmentSide(): ?WorkOrderSide
    {
        return null;
    }

    /**
     * Whether comments may be added, edited, or deleted. Final statuses keep
     * their comments read-only.
     */
    public function acceptsComments(): bool
    {
        return true;
    }

    /**
     * Whether a work order in this status is late once its target date has
     * passed (the dashboard's "Terlambat"): submitted and not yet final.
     * Every new status must decide this; draft and final statuses never are.
     */
    public function countsAsOverdueWhenLate(): bool
    {
        return false;
    }

    /**
     * Whether a work order needs a target department to enter this status.
     * True for every status after the first submission (FLOW.md §5), so a
     * submitted work order never lacks one; every new status must decide it.
     */
    public function requiresTargetDepartment(): bool
    {
        return false;
    }

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Draft::class)
            ->allowTransition(Draft::class, Diajukan::class)
            ->allowTransition(Diajukan::class, Dikerjakan::class)
            ->allowTransition(Diajukan::class, Ditolak::class)
            ->allowTransition(Ditolak::class, Diajukan::class)
            ->allowTransition([Draft::class, Diajukan::class, Ditolak::class], Dibatalkan::class);
    }

    /**
     * The state for a stored status name, or null for a name no longer defined
     * (history rows outlive changes to the flow).
     */
    public static function fromName(string $name): ?self
    {
        $class = self::getStateMapping()->get($name);

        if (! is_string($class) || ! is_subclass_of($class, self::class)) {
            return null;
        }

        return new $class(new WorkOrder);
    }

    /**
     * The label for a stored status name, falling back to the name itself.
     */
    public static function labelFor(string $name): string
    {
        return self::fromName($name)?->label() ?? $name;
    }

    /**
     * The stored names of every status that countsAsOverdueWhenLate().
     *
     * @return list<string>
     */
    public static function overdueWhenLateNames(): array
    {
        $names = [];

        foreach (self::getStateMapping()->keys() as $name) {
            if (self::fromName((string) $name)?->countsAsOverdueWhenLate()) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * Every status in flow order, for filters and badges.
     *
     * @return list<array{value: string, label: string, tone: string}>
     */
    public static function options(): array
    {
        return array_map(fn (string $class): array => new $class(new WorkOrder)->toOption(), [
            Draft::class,
            Diajukan::class,
            Ditolak::class,
            Dikerjakan::class,
            Dibatalkan::class,
        ]);
    }

    /**
     * @return array{value: string, label: string, tone: string}
     */
    public function toOption(): array
    {
        return ['value' => $this->getValue(), 'label' => $this->label(), 'tone' => $this->tone()];
    }
}
