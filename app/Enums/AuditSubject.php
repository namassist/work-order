<?php

namespace App\Enums;

/**
 * Morph aliases that appear as activity log subjects, with display labels.
 */
enum AuditSubject: string
{
    case User = 'user';
    case Department = 'department';
    case WorkOrderCategory = 'wo-category';
    case Role = 'role';

    /**
     * The label shown in the activity log.
     */
    public function label(): string
    {
        return match ($this) {
            self::User => 'Pengguna',
            self::Department => 'Departemen',
            self::WorkOrderCategory => 'Kategori WO',
            self::Role => 'Role',
        };
    }

    /**
     * Subjects whose edit screens show a history panel.
     *
     * @return list<string>
     */
    public static function withHistoryPanel(): array
    {
        return [self::User->value, self::Department->value, self::WorkOrderCategory->value];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $subject): array => ['value' => $subject->value, 'label' => $subject->label()], self::cases());
    }
}
