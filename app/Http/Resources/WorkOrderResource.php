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
            // An account (id set) or, when entered on behalf, a contact name (id null).
            'requester' => ['id' => $workOrder->requester?->id, 'name' => $workOrder->requesterName()],
            // Only the name: IC users see who entered their work order (a
            // koordinator of the executor company), nothing else about them.
            'entered_by' => ['name' => $workOrder->enteredBy->name],
            'created_at' => $workOrder->created_at?->toIso8601String(),
            'updated_at' => $workOrder->updated_at?->toIso8601String(),
            'deleted_at' => $workOrder->deleted_at?->toIso8601String(),
        ];
    }
}
