<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * How the UI names a role: its display label ("Admin WO"), or its slug name
 * (admin-wo) when it has none. Code, seeders, tests, and the audit log use
 * the name; only what people read uses the label. Takes any role model,
 * since the permission package's role class is configurable (a user's
 * eager-loaded roles are typed as plain models).
 */
class RoleLabel
{
    public static function of(Model $role): string
    {
        $label = $role->getAttribute('label');

        return is_string($label) && $label !== '' ? $label : (string) $role->getAttribute('name');
    }

    /**
     * The role as the frontend lists it.
     *
     * @return array{name: string, label: string}
     */
    public static function option(Model $role): array
    {
        return ['name' => (string) $role->getAttribute('name'), 'label' => self::of($role)];
    }
}
