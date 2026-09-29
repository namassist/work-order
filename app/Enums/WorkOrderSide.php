<?php

namespace App\Enums;

/**
 * The sides of a work order (FLOW.md §5). PROVISIONAL until step 3 replaces
 * the v1 statuses: the requester side is Admin WO (work-orders.update), the
 * executor side Lead Operational (work-orders.process), and the finance side
 * Finance (work-orders.confirm-payment), each any executor company user
 * holding the permission. See WorkOrder::isOnSide().
 */
enum WorkOrderSide: string
{
    case Requester = 'requester';
    case Executor = 'executor';
    case Finance = 'finance';
}
