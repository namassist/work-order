<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WorkOrderCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A plain-text comment on a work order, shown in its timeline. Written only
 * through the WorkOrders comment actions, which log to the work order's audit
 * trail without the text. A deleted comment stays in the timeline as
 * "Komentar dihapus".
 *
 * @property int $id
 * @property int $work_order_id
 * @property int $user_id
 * @property string $body
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read WorkOrder $workOrder
 * @property-read User $author
 */
#[Fillable(['body'])]
class WorkOrderComment extends Model
{
    /** @use HasFactory<WorkOrderCommentFactory> */
    use HasFactory, SoftDeletes;

    public const int MAX_BODY_LENGTH = 2000;

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    /**
     * The user who wrote the comment, even if they were deleted later.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * Whether the author may still edit or delete the comment: until
     * work_order.comments.edit_window_minutes after it was posted.
     */
    public function isWithinEditWindow(): bool
    {
        return now()->lessThanOrEqualTo(
            $this->created_at->addMinutes(config()->integer('work_order.comments.edit_window_minutes')),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }
}
