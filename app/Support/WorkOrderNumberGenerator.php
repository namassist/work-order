<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Hands out work order numbers in the configured format
 * (config work_order.number_format), counted per scope by NumberSequence:
 * the default format counts per department per month.
 */
class WorkOrderNumberGenerator
{
    /**
     * The next number for a department at the given moment (in the display
     * timezone). Must run inside the transaction that stores the number.
     */
    public function next(string $departmentCode, CarbonInterface $at): string
    {
        return new NumberSequence('work_order_number_sequences', 'work_order.number_format')->next($departmentCode, $at);
    }
}
