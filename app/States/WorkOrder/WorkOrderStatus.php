<?php

namespace App\States\WorkOrder;

use App\Enums\Permission;
use App\Enums\WorkOrderDeadline;
use App\Models\WorkOrder;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Work order status (FLOW.md §5). Every state and allowed transition is
 * defined in this folder and nowhere else; the UI gets them from options()
 * and the available transitions of each WO. Each status lists the changes
 * it may make in transitions(), each guarded by its own permission.
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
     * Every status, in flow order.
     *
     * @var list<class-string<self>>
     */
    private const array FLOW_ORDER = [
        Draft::class,
        Diajukan::class,
        Ditolak::class,
        Pelaksanaan::class,
        ReviewDokumen::class,
        ApprovalBast::class,
        BastDisetujui::class,
        Closed::class,
        Dibatalkan::class,
    ];

    /**
     * The state machine, built once from every status's transitions().
     */
    private static ?StateConfig $config = null;

    /**
     * The status label shown in badges, lists, and the timeline.
     */
    abstract public function label(): string;

    /**
     * The badge colour token from docs/DESIGN.md: secondary, warning,
     * destructive, info, review, approval, approved, success, or muted.
     */
    abstract public function tone(): string;

    /**
     * Whether a work order in this status is still open work: submitted and
     * not final (Diajukan through BAST Disetujui). The list's
     * "Aktif" status group and the dashboard's "WO Mendesak" read it, so
     * every new status must decide it.
     */
    abstract public function isActive(): bool;

    /**
     * The status changes a work order in this status may make, each with
     * the permission that makes it (FLOW.md §5.1, §5.2). config() builds the
     * state machine from these, so nothing else defines a transition. Static,
     * because building the config may not create states (each state's
     * constructor reads the config).
     *
     * @return list<WorkOrderTransition>
     */
    public static function transitions(): array
    {
        return [];
    }

    /**
     * The allowed change to the given status, or null when this status
     * cannot move there.
     */
    public function transitionFor(string $to): ?WorkOrderTransition
    {
        return array_find(static::transitions(), fn (WorkOrderTransition $transition): bool => $transition->toName() === $to);
    }

    /**
     * The permissions of whoever's turn it is while a work order is in this
     * status: those of its transitions other than cancelling. Empty when
     * nobody has to act (final statuses).
     *
     * @return list<Permission>
     */
    public function waitsOn(): array
    {
        return array_values(array_unique(array_map(
            fn (WorkOrderTransition $transition): Permission => $transition->permission,
            array_filter(static::transitions(), fn (WorkOrderTransition $transition): bool => ! $transition->isCancellation()),
        ), SORT_REGULAR));
    }

    /**
     * Who the work order waits for, shown to users who hold none of the
     * waitsOn() permissions.
     */
    public function waitingMessage(): ?string
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
     * Whether the work order's fields may still be edited.
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
     * Which permission may add and remove files in which attachment
     * collection while a work order is in this status (FLOW.md §5.3). A
     * collection that is not listed cannot be changed by anyone.
     *
     * @return array<string, Permission>
     */
    public function attachmentPermissions(): array
    {
        return [];
    }

    /**
     * The permission that may change the collection's files in this status,
     * or null when nobody may.
     */
    public function attachmentPermissionFor(string $collection): ?Permission
    {
        return $this->attachmentPermissions()[$collection] ?? null;
    }

    /**
     * Whether comments may be added, edited, or deleted. Final statuses keep
     * their comments read-only (Closed only once paid, FLOW.md §5.3).
     */
    public function acceptsComments(): bool
    {
        return true;
    }

    /**
     * The date a work order in this status is late against once it has
     * passed (FLOW.md §11, the dashboard's "Terlambat"), or null when it is
     * never late in this status. Every new status must decide this.
     */
    public function deadline(): ?WorkOrderDeadline
    {
        return null;
    }

    /**
     * Whether a work order in this status has a number: true for every
     * status after the first submission (FLOW.md §5.3), backed by the
     * work_orders_submitted_number_check constraint; every new status must
     * decide it.
     */
    public function requiresNumber(): bool
    {
        return false;
    }

    public static function config(): StateConfig
    {
        if (self::$config instanceof StateConfig) {
            return self::$config;
        }

        $config = parent::config()->default(Draft::class);

        foreach (self::FLOW_ORDER as $class) {
            foreach ($class::transitions() as $transition) {
                $config->allowTransition($class, $transition->to);
            }
        }

        return self::$config = $config;
    }

    /**
     * Every permission that makes some status change, for telling a user
     * who acts on work orders at all from one who never does.
     *
     * @return list<Permission>
     */
    public static function transitionPermissions(): array
    {
        $permissions = [];

        foreach (self::FLOW_ORDER as $class) {
            foreach ($class::transitions() as $transition) {
                $permissions[$transition->permission->value] = $transition->permission;
            }
        }

        return array_values($permissions);
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
        return array_map(fn (string $class): self => new $class(new WorkOrder), self::FLOW_ORDER);
    }

    /**
     * @return array{value: string, label: string, tone: string}
     */
    public function toOption(): array
    {
        return ['value' => $this->getValue(), 'label' => $this->label(), 'tone' => $this->tone()];
    }
}
