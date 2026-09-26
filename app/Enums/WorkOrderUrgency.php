<?php

namespace App\Enums;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How urgent the requester says a work order is. Shown with an icon and
 * text, never with the status colours (docs/DESIGN.md › Urgensi Work Order).
 */
enum WorkOrderUrgency: string
{
    case Rendah = 'rendah';
    case Normal = 'normal';
    case Tinggi = 'tinggi';
    case Mendesak = 'mendesak';

    public function label(): string
    {
        return match ($this) {
            self::Rendah => 'Rendah',
            self::Normal => 'Normal',
            self::Tinggi => 'Tinggi',
            self::Mendesak => 'Mendesak',
        };
    }

    /**
     * @return array{value: string, label: string}
     */
    public function toOption(): array
    {
        return ['value' => $this->value, 'label' => $this->label()];
    }

    /**
     * Every urgency, least urgent first.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $urgency): array => $urgency->toOption(), self::cases());
    }

    /**
     * Order the query most urgent first. The SQL is a literal with one
     * "when" per case, most urgent first; keep it in step with the cases.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function orderMostUrgentFirst(Builder $query): Builder
    {
        return $query->orderByRaw(
            'case urgency when ? then 4 when ? then 3 when ? then 2 when ? then 1 else 0 end desc',
            [self::Mendesak->value, self::Tinggi->value, self::Normal->value, self::Rendah->value],
        );
    }
}
