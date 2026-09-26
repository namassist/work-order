<?php

namespace App\States\WorkOrder;

use App\Models\WorkOrder;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Work order status. PROVISIONAL: Draft, Diajukan, and Dibatalkan stand in
 * until the real approval and execution flow is designed. Every state and
 * allowed transition is defined in this folder and nowhere else; the UI gets
 * them from options() and the available transitions of each WO.
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
     * success, or destructive.
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
     * Whether moving into this status requires a note.
     */
    public function requiresNote(): bool
    {
        return false;
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

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Draft::class)
            ->allowTransition(Draft::class, Diajukan::class)
            ->allowTransition([Draft::class, Diajukan::class], Dibatalkan::class);
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
     * Every status in flow order, for filters and badges.
     *
     * @return list<array{value: string, label: string, tone: string}>
     */
    public static function options(): array
    {
        return array_map(fn (string $class): array => new $class(new WorkOrder)->toOption(), [
            Draft::class,
            Diajukan::class,
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
