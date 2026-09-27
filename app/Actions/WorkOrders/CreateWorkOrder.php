<?php

namespace App\Actions\WorkOrders;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Creates a draft work order and writes the first status history row
 * (null → draft). An IC user enters it for their own department as its
 * requester; a koordinator enters it on behalf of an IC department, for an
 * account there or for a contact without one (FLOW.md §4).
 */
class CreateWorkOrder
{
    /**
     * @param  array<string, mixed>  $attributes  fillable work order fields
     * @param  Department|null  $requesterDepartment  on behalf: the IC department; null: the one who enters it
     * @param  User|null  $requester  on behalf: the requester's account (null with a contact name)
     * @param  string|null  $contactName  on behalf: the requester without an account
     */
    public function handle(
        array $attributes,
        User $enteredBy,
        ?Department $requesterDepartment = null,
        ?User $requester = null,
        ?string $contactName = null,
    ): WorkOrder {
        return DB::transaction(function () use ($attributes, $enteredBy, $requesterDepartment, $requester, $contactName): WorkOrder {
            $onBehalf = $requesterDepartment instanceof Department;

            $workOrder = new WorkOrder($attributes);
            $workOrder->requester_department_id = $onBehalf ? $requesterDepartment->id : $enteredBy->department_id;
            $workOrder->requester_id = $onBehalf ? $requester?->id : $enteredBy->id;
            $workOrder->requester_name = $onBehalf && ! $requester instanceof User ? $contactName : null;
            $workOrder->created_by = $enteredBy->id;
            $workOrder->save();

            $workOrder->statusHistories()->create([
                'from_status' => null,
                'to_status' => $workOrder->status->getValue(),
                'user_id' => $enteredBy->id,
            ]);

            return $workOrder;
        });
    }
}
