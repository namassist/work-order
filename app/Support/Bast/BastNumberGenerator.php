<?php

namespace App\Support\Bast;

use App\Support\NumberSequence;
use Carbon\CarbonInterface;

/**
 * Hands out BAST numbers in the configured format
 * (config work_order.bast.number_format), assigned when Rental submits the
 * BAST (FLOW.md §8). {DEPT_CODE} is the requester department's code, as in
 * the work order number; the default format counts per month.
 */
class BastNumberGenerator
{
    /**
     * The next number for a work order of the requester department at the
     * given moment (in the display timezone). Must run inside the
     * transaction that stores the number.
     */
    public function next(string $requesterDepartmentCode, CarbonInterface $at): string
    {
        return new NumberSequence('bast_number_sequences', 'work_order.bast.number_format')->next($requesterDepartmentCode, $at);
    }
}
