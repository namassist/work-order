<?php

namespace App\Enums;

/**
 * Roles the application code depends on. Other roles are plain data.
 */
enum SystemRole: string
{
    /**
     * Always holds every permission; cannot be renamed, reduced, or deleted.
     */
    case Admin = 'admin';
}
