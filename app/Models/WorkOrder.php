<?php

namespace App\Models;

use App\Concerns\HasAttachments;
use App\Concerns\LogsModelActivity;
use App\Concerns\SearchesColumns;
use App\Enums\Permission;
use App\Enums\WorkOrderUrgency;
use App\States\WorkOrder\WorkOrderStatus;
use App\Support\Attachments\Attachable;
use App\Support\Attachments\AttachmentCollection;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\ModelStates\HasStates;

/**
 * @property int $id
 * @property string|null $number
 * @property string $title
 * @property string|null $description
 * @property int $department_id
 * @property int $work_order_category_id
 * @property int $created_by
 * @property WorkOrderStatus $status
 * @property WorkOrderUrgency $urgency
 * @property Carbon|null $target_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Department $department
 * @property-read WorkOrderCategory $category
 * @property-read User $requester
 * @property-read Collection<int, WorkOrderComment> $comments
 */
#[Fillable(['title', 'description', 'work_order_category_id', 'urgency', 'target_date'])]
class WorkOrder extends Model implements Attachable
{
    /** @use HasFactory<WorkOrderFactory> */
    use HasAttachments, HasFactory, HasStates, LogsModelActivity, SearchesColumns, SoftDeletes;

    /**
     * Supporting documents. Later stages (BAST, invoice) add their own collections.
     */
    public const string DOCUMENTS = 'dokumen';

    /**
     * The model's default values for attributes, matching the columns' defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'urgency' => 'normal',
    ];

    /**
     * @return array<string, AttachmentCollection>
     */
    public function attachmentCollections(): array
    {
        return [
            self::DOCUMENTS => new AttachmentCollection(
                self::DOCUMENTS,
                maxFiles: (int) config('work_order.attachments.dokumen.max_files'),
                maxSizeKb: (int) config('work_order.attachments.dokumen.max_size_kb'),
            ),
        ];
    }

    /**
     * The rules of the 'dokumen' collection.
     */
    public function documentsCollection(): AttachmentCollection
    {
        return $this->attachmentCollections()[self::DOCUMENTS];
    }

    /**
     * The requester's department at creation, even if it was deleted later.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /**
     * @return BelongsTo<WorkOrderCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkOrderCategory::class, 'work_order_category_id')->withTrashed();
    }

    /**
     * The user who created the work order.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * Status changes, oldest first.
     *
     * @return HasMany<WorkOrderStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(WorkOrderStatusHistory::class)->oldest('created_at')->oldest('id');
    }

    /**
     * Comments, oldest first. Deleted ones are included only on request
     * (withTrashed), for the timeline's "Komentar dihapus" placeholders.
     *
     * @return HasMany<WorkOrderComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(WorkOrderComment::class)->oldest('created_at')->oldest('id');
    }

    /**
     * The number, or "Draft" until the work order is submitted.
     */
    public function displayNumber(): string
    {
        return $this->number ?? 'Draft';
    }

    /**
     * Whether the user may see this work order: their own department's, or
     * any with work-orders.view-all.
     */
    public function isVisibleTo(User $user): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrdersViewAll->value)
            || ($user->department_id !== null && $user->department_id === $this->department_id);
    }

    /**
     * Only the work orders the user may see, see isVisibleTo().
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->checkPermissionTo(Permission::WorkOrdersViewAll->value)) {
            return;
        }

        if ($user->department_id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('department_id', $user->department_id);
    }

    /**
     * Search by number or title.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $this->searchColumns($query, $term, ['number', 'title']);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'urgency' => WorkOrderUrgency::class,
            'target_date' => 'date:Y-m-d',
        ];
    }
}
