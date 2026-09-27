<?php

namespace App\Models;

use App\Concerns\HasAttachments;
use App\Concerns\LogsModelActivity;
use App\Concerns\SearchesColumns;
use App\Enums\Permission;
use App\Enums\WorkOrderUrgency;
use App\States\WorkOrder\Diajukan;
use App\States\WorkOrder\WorkOrderStatus;
use App\Support\Attachments\Attachable;
use App\Support\Attachments\AttachmentCollection;
use App\Support\DisplayDate;
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
use Spatie\Activitylog\Support\LogOptions;
use Spatie\ModelStates\HasStates;

/**
 * @property int $id
 * @property string|null $number
 * @property string $title
 * @property string|null $description
 * @property int $requester_department_id
 * @property int|null $target_department_id
 * @property int|null $requester_id
 * @property string|null $requester_name
 * @property int $work_order_category_id
 * @property int $created_by
 * @property WorkOrderStatus $status
 * @property WorkOrderUrgency $urgency
 * @property Carbon|null $target_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Department $requesterDepartment
 * @property-read Department|null $targetDepartment
 * @property-read WorkOrderCategory $category
 * @property-read User|null $requester
 * @property-read User $enteredBy
 * @property-read Collection<int, WorkOrderComment> $comments
 */
#[Fillable(['title', 'description', 'work_order_category_id', 'target_department_id', 'urgency', 'target_date'])]
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
     * Log the requester too: it is not fillable (the action sets it) but may
     * be corrected on an on-behalf draft.
     */
    protected function activityLogOptions(LogOptions $options): LogOptions
    {
        return $options->logOnly(['requester_id', 'requester_name']);
    }

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
     * The department (of a client company) that requests the work, fixed at
     * creation, even if it was deleted later.
     *
     * @return BelongsTo<Department, $this>
     */
    public function requesterDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'requester_department_id')->withTrashed();
    }

    /**
     * The department (of the executor company) the work is addressed to;
     * required from the first submission on (requiresTargetDepartment()).
     *
     * @return BelongsTo<Department, $this>
     */
    public function targetDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'target_department_id')->withTrashed();
    }

    /**
     * @return BelongsTo<WorkOrderCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkOrderCategory::class, 'work_order_category_id')->withTrashed();
    }

    /**
     * The requester's account; null when the work order was entered on behalf
     * of someone without one (see requester_name).
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id')->withTrashed();
    }

    /**
     * The user who entered the work order: the requester, or a koordinator
     * entering it on their behalf.
     *
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * Whether someone other than the requester entered the work order (a
     * koordinator, for an IC account or a contact without one).
     */
    public function wasEnteredOnBehalf(): bool
    {
        return $this->requester_id !== $this->created_by;
    }

    /**
     * The requester's name: their account's, or the contact name.
     */
    public function requesterName(): string
    {
        return $this->requester->name ?? (string) $this->requester_name;
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
     * Whether the user may see this work order (FLOW.md §6). Client company
     * (IC) users see their own department's work orders, drafts included.
     * Executor company (Unggul) users see those they entered, and submitted
     * ones addressed to their department, or all submitted ones with
     * work-orders.view-all; never another user's draft. Must agree with
     * scopeVisibleTo(), see WorkOrderVisibilityTest.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->isClient()) {
            return $this->requester_department_id === $user->department_id;
        }

        if ($this->created_by === $user->id) {
            return true;
        }

        return $this->wasSubmitted()
            && ($user->checkPermissionTo(Permission::WorkOrdersViewAll->value) || $this->target_department_id === $user->department_id);
    }

    /**
     * Whether the work order was ever submitted: it gets its number then and
     * keeps it, whatever happens next.
     */
    public function wasSubmitted(): bool
    {
        return $this->number !== null;
    }

    /**
     * Only the work orders the user may see, see isVisibleTo().
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->isClient()) {
            $query->where('requester_department_id', $user->department_id);

            return;
        }

        $seesAllSubmitted = $user->checkPermissionTo(Permission::WorkOrdersViewAll->value);

        $query->where(fn (Builder $query) => $query
            ->where('created_by', $user->id)
            ->orWhere(fn (Builder $query) => $query
                ->whereNotNull('number')
                ->when(! $seesAllSubmitted, fn (Builder $query) => $query->where('target_department_id', $user->department_id))));
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
     * Late work orders (the dashboard's "Terlambat"): in a status that
     * countsAsOverdueWhenLate() with a target date before today in the display
     * timezone. Work orders without a target date are never late.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->whereIn('status', WorkOrderStatus::overdueWhenLateNames())
            ->whereNotNull('target_date')
            ->where('target_date', '<', DisplayDate::today());
    }

    /**
     * Adds `submitted_at`: the moment of the first submission (the first
     * Diajukan status history row), or null for a work order never submitted.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withSubmittedAt(Builder $query): void
    {
        $query->addSelect(['submitted_at' => WorkOrderStatusHistory::query()
            ->select('created_at')
            ->whereColumn('work_order_id', 'work_orders.id')
            ->where('to_status', Diajukan::$name)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(1)])
            ->withCasts(['submitted_at' => 'datetime']);
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
