<?php

namespace App\Actions\WorkOrders;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Creates a draft work order for its requester's department, with the
 * requester as the one who entered it, and writes the first status history
 * row (null → draft).
 */
class CreateWorkOrder
{
    /**
     * @param  array<string, mixed>  $attributes  fillable work order fields
     */
    public function handle(array $attributes, User $requester): WorkOrder
    {
        return DB::transaction(function () use ($attributes, $requester): WorkOrder {
            $workOrder = new WorkOrder($attributes);
            $workOrder->requester_department_id = $requester->department_id;
            $workOrder->requester_id = $requester->id;
            $workOrder->created_by = $requester->id;
            $workOrder->save();

            $workOrder->statusHistories()->create([
                'from_status' => null,
                'to_status' => $workOrder->status->getValue(),
                'user_id' => $requester->id,
            ]);

            return $workOrder;
        });
    }
}
