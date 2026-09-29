<?php

namespace App\Models;

use App\Concerns\HasAttachments;
use App\Enums\AttachmentType;
use App\Support\Attachments\Attachable;
use App\Support\Attachments\AttachmentCollection;
use Carbon\CarbonImmutable;
use Database\Factories\WorkOrderCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A rich-text comment on a work order, shown in its timeline (FLOW.md §9).
 * `body` is HTML sanitized by CommentHtml, `body_text` its plain text. Its
 * inline images and documents are attachments of the comment, claimed from
 * the author's pending uploads on the work order. Written only through the
 * WorkOrders comment actions, which log to the work order's audit trail
 * without the text. A deleted comment stays in the timeline as "Komentar
 * dihapus", and its files are deleted. Posting, editing, or deleting a
 * comment bumps the work order's updated_at, its last activity.
 *
 * @property int $id
 * @property int $work_order_id
 * @property int $user_id
 * @property string $body
 * @property string $body_text
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read WorkOrder $workOrder
 * @property-read User $author
 */
#[Fillable(['body', 'body_text'])]
#[Touches(['workOrder'])]
class WorkOrderComment extends Model implements Attachable
{
    /** @use HasFactory<WorkOrderCommentFactory> */
    use HasAttachments, HasFactory, SoftDeletes;

    /**
     * Images shown inside the body.
     */
    public const string IMAGES = 'gambar';

    /**
     * Documents listed below the body.
     */
    public const string DOCUMENTS = 'lampiran';

    /**
     * Types accepted as inline images.
     *
     * @var list<AttachmentType>
     */
    public const array IMAGE_TYPES = [AttachmentType::Jpeg, AttachmentType::Png, AttachmentType::Webp];

    /**
     * @return array<string, AttachmentCollection>
     */
    public function attachmentCollections(): array
    {
        return [
            self::IMAGES => new AttachmentCollection(
                self::IMAGES,
                maxFiles: config()->integer('work_order.comments.images.max_files'),
                maxSizeKb: config()->integer('work_order.comments.images.max_size_kb'),
                types: self::IMAGE_TYPES,
            ),
            self::DOCUMENTS => new AttachmentCollection(
                self::DOCUMENTS,
                maxFiles: config()->integer('work_order.comments.documents.max_files'),
                maxSizeKb: config()->integer('work_order.comments.documents.max_size_kb'),
            ),
        ];
    }

    /**
     * The documents listed below the body, oldest first.
     *
     * @return MorphMany<Media, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Media::class, 'model')
            ->where('collection_name', self::DOCUMENTS)
            ->orderBy('created_at')
            ->orderBy('id');
    }

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
