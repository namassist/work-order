<?php

namespace App\Models;

use App\States\WorkOrder\WorkOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One status change of a work order. Written only by TransitionWorkOrder and
 * on creation; never updated. Statuses are stored as plain names so rows stay
 * readable after the provisional flow changes.
 *
 * @property int $id
 * @property int $work_order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int $user_id
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read User $user
 */
#[Fillable(['from_status', 'to_status', 'user_id', 'note'])]
class WorkOrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return array{id: int, from: array{value: string, label: string}|null, to: array{value: string, label: string}, user: array{id: int, name: string}, note: string|null, created_at: string}
     */
    public function toTimelineEntry(): array
    {
        return [
            'id' => $this->id,
            'from' => $this->from_status === null ? null : ['value' => $this->from_status, 'label' => WorkOrderStatus::labelFor($this->from_status)],
            'to' => ['value' => $this->to_status, 'label' => WorkOrderStatus::labelFor($this->to_status)],
            'user' => ['id' => $this->user->id, 'name' => $this->user->name],
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
