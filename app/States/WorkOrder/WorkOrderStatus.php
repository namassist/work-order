<?php

namespace App\States\WorkOrder;

use App\Enums\WorkOrderDeadline;
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
     * The status filter value for every active status (isActive()).
     */
    public const string GROUP_ACTIVE = 'aktif';

    /**
     * The status label shown in badges, lists, and the timeline.
     */
    abstract public function label(): string;

    /**
     * The badge colour token from docs/DESIGN.md: secondary, warning, info,
     * billing, success, destructive, or muted.
     */
    abstract public function tone(): string;

    /**
     * Whether a work order in this status is still open work: submitted and
     * not final (Diajukan, Ditolak, Dikerjakan, Penagihan). The list's
     * "Aktif" status group and the dashboard's "WO Mendesak" read it, so
     * every new status must decide it.
     */
    abstract public function isActive(): bool;

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
     * Which side may add and remove files in which attachment collection
     * while a work order is in this status (FLOW.md §5 status properties).
     * A collection that is not listed cannot be changed by anyone.
     *
     * @return array<string, WorkOrderSide>
     */
    public function attachmentSides(): array
    {
        return [];
    }

    /**
     * The side that may change the collection's files in this status, or
     * null when nobody may.
     */
    public function attachmentSideFor(string $collection): ?WorkOrderSide
    {
        return $this->attachmentSides()[$collection] ?? null;
    }

    /**
     * The form that collects what moving into this status needs, when the
     * plain transition (a note at most) is not enough: 'invoice' for
     * Penagihan (BillWorkOrder), 'payment' for Selesai
     * (ConfirmWorkOrderPayment). Such a status is never entered through
     * the plain transition endpoint.
     */
    public function transitionForm(): ?string
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
     * The date a work order in this status is late against once it has
     * passed (FLOW.md §7, the dashboard's "Terlambat"), or null when it is
     * never late in this status. Every new status must decide this: only
     * submitted, non-final statuses have one; draft and final statuses never
     * do.
     */
    public function deadline(): ?WorkOrderDeadline
    {
        return null;
    }

    /**
     * Whether a work order in this status has a number: true for every
     * status after the first submission (FLOW.md §5), backed by the
     * work_orders_submitted_number_check constraint; every new status must
     * decide it.
     */
    public function requiresNumber(): bool
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
            ->allowTransition(Dikerjakan::class, Penagihan::class)
            ->allowTransition(Penagihan::class, Selesai::class)
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
     * The stored names of every status that has a deadline(), per deadline.
     *
     * @return array<string, list<string>> keyed by WorkOrderDeadline value
     */
    public static function namesByDeadline(): array
    {
        $names = [];

        foreach (self::getStateMapping()->keys() as $name) {
            $deadline = self::fromName((string) $name)?->deadline();

            if ($deadline instanceof WorkOrderDeadline) {
                $names[$deadline->value][] = (string) $name;
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
        return array_map(fn (self $state): array => $state->toOption(), self::inFlowOrder());
    }

    /**
     * The stored names of every status, in flow order.
     *
     * @return list<string>
     */
    public static function flowOrder(): array
    {
        return array_map(fn (self $state): string => $state->getValue(), self::inFlowOrder());
    }

    /**
     * The stored names of the statuses whose isActive() is true, in flow order.
     *
     * @return list<string>
     */
    public static function activeNames(): array
    {
        return array_values(array_map(
            fn (self $state): string => $state->getValue(),
            array_filter(self::inFlowOrder(), fn (self $state): bool => $state->isActive()),
        ));
    }

    /**
     * The status filter's groups, offered before the single statuses.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function groupOptions(): array
    {
        return [['value' => self::GROUP_ACTIVE, 'label' => 'Aktif']];
    }

    /**
     * The stored names a status filter value stands for: a group's
     * statuses, or the single status itself.
     *
     * @return list<string>
     */
    public static function namesFor(string $filter): array
    {
        return $filter === self::GROUP_ACTIVE ? self::activeNames() : [$filter];
    }

    /**
     * The label of a status filter value: a group's or a status's.
     */
    public static function filterLabelFor(string $filter): string
    {
        $group = array_find(self::groupOptions(), fn (array $option): bool => $option['value'] === $filter);

        return $group['label'] ?? self::labelFor($filter);
    }

    /**
     * @return list<self>
     */
    private static function inFlowOrder(): array
    {
        return array_map(fn (string $class): self => new $class(new WorkOrder), [
            Draft::class,
            Diajukan::class,
            Ditolak::class,
            Dikerjakan::class,
            Penagihan::class,
            Selesai::class,
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
