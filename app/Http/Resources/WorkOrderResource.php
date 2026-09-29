<?php

namespace App\Http\Resources;

use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A work order for lists and the detail page. Load
 * WorkOrderController::LIST_RELATIONS first.
 *
 * @property WorkOrder $resource
 */
class WorkOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workOrder = $this->resource;

        return [
            'id' => $workOrder->id,
            'number' => $workOrder->number,
            'display_number' => $workOrder->displayNumber(),
            'title' => $workOrder->title,
            'description' => $workOrder->description,
            'status' => $workOrder->status->toOption(),
            'urgency' => $workOrder->urgency->toOption(),
            'target_date' => $workOrder->target_date?->toDateString(),
            'requester_department' => $workOrder->requesterDepartment->only(['id', 'code', 'name']),
            'target_department' => $workOrder->targetDepartment?->only(['id', 'code', 'name']),
            'category' => $workOrder->category->only(['id', 'code', 'name']),
            // The IC contact who made the request (FLOW.md §4).
            'requester_name' => $workOrder->requester_name,
            'pic_name' => $workOrder->pic_name,
            // Only the name of the Admin WO who entered it: the v1 isolation
            // safeguard still shows client company users nothing more.
            'entered_by' => ['name' => $workOrder->enteredBy->name],
            'created_at' => $workOrder->created_at?->toIso8601String(),
            'updated_at' => $workOrder->updated_at?->toIso8601String(),
            'deleted_at' => $workOrder->deleted_at?->toIso8601String(),
        ];
    }
}
