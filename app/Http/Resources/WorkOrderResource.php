<?php

namespace App\Http\Resources;

use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A work order for lists and the detail page. Load department, category, and
 * requester first.
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
            'target_date' => $workOrder->target_date?->toDateString(),
            'department' => $workOrder->department->only(['id', 'code', 'name']),
            'category' => $workOrder->category->only(['id', 'code', 'name']),
            'requester' => $workOrder->requester->only(['id', 'name']),
            'created_at' => $workOrder->created_at?->toIso8601String(),
            'deleted_at' => $workOrder->deleted_at?->toIso8601String(),
        ];
    }
}
