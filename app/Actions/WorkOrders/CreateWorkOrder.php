<?php

namespace App\Actions\WorkOrders;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Creates a draft work order and writes the first status history row
 * (null → draft). An Admin WO enters it on behalf of an IC department and
 * contact (FLOW.md v2 §4); the attributes carry both.
 */
class CreateWorkOrder
{
    /**
     * @param  array<string, mixed>  $attributes  fillable work order fields, requester department and contact name included
     */
    public function handle(array $attributes, User $enteredBy): WorkOrder
    {
        return DB::transaction(function () use ($attributes, $enteredBy): WorkOrder {
            $workOrder = new WorkOrder($attributes);
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
