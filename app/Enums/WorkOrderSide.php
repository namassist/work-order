<?php

namespace App\Enums;

/**
 * The sides of a work order (FLOW.md §5). The requester side is the
 * requester department's users with work-orders.update, or the koordinator
 * who entered it on their behalf; the executor side is the target
 * department's users with work-orders.process; the finance side is any
 * executor company user with work-orders.confirm-payment (keuangan), who
 * confirms that an invoice was paid. See WorkOrder::isOnSide().
 */
enum WorkOrderSide: string
{
    case Requester = 'requester';
    case Executor = 'executor';
    case Finance = 'finance';
}
