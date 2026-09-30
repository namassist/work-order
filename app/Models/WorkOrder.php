<?php

namespace App\Models;

use App\Concerns\HasAttachments;
use App\Concerns\LogsModelActivity;
use App\Concerns\SearchesColumns;
use App\Enums\AttachmentType;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\WorkOrderDeadline;
use App\Enums\WorkOrderUrgency;
use App\States\WorkOrder\Closed;
use App\States\WorkOrder\Diajukan;
use App\States\WorkOrder\Pelaksanaan;
use App\States\WorkOrder\ReviewDokumen;
use App\States\WorkOrder\WorkOrderStatus;
use App\Support\Attachments\Attachable;
use App\Support\Attachments\AttachmentCollection;
use App\Support\DailyReports\ReportCalendar;
use App\Support\DisplayDate;
use Carbon\CarbonInterface;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\ModelStates\HasStates;

/**
 * @property int $id
 * @property string|null $number
 * @property string $title
 * @property string|null $description
 * @property int $requester_department_id
 * @property int|null $target_department_id
 * @property string $requester_name
 * @property string|null $pic_name
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
 * @property-read User $enteredBy
 * @property-read Collection<int, WorkOrderComment> $comments
 * @property-read WorkOrderInvoice|null $invoice
 * @property-read Collection<int, WorkOrderDailyReport> $dailyReports
 */
#[Fillable(['title', 'description', 'requester_department_id', 'requester_name', 'pic_name', 'work_order_category_id', 'target_department_id', 'urgency', 'target_date'])]
class WorkOrder extends Model implements Attachable
{
    /** @use HasFactory<WorkOrderFactory> */
    use HasAttachments, HasFactory, HasStates, LogsModelActivity, SearchesColumns, SoftDeletes;

    /**
     * Supporting documents: Admin WO's with the request, PIC Timesheet's
     * during Pelaksanaan.
     */
    public const string DOCUMENTS = 'dokumen';

    /**
     * The invoice itself (FLOW.md §10): at least one file once billed.
     */
    public const string INVOICE = 'invoice';

    /**
     * Proof of payment, added by the finance side.
     */
    public const string PAYMENT_PROOF = 'bukti_bayar';

    /**
     * A comment's inline images while they wait, as their uploader's pending
     * uploads, for the comment that claims them (WorkOrderComment::IMAGES).
     */
    public const string COMMENT_IMAGE_UPLOADS = 'komentar_gambar';

    /**
     * A comment's documents while they wait for the comment that claims
     * them (WorkOrderComment::DOCUMENTS).
     */
    public const string COMMENT_FILE_UPLOADS = 'komentar_lampiran';

    /**
     * Types accepted for invoices and proof of payment: documents and scans.
     */
    private const array SCAN_TYPES = [AttachmentType::Pdf, AttachmentType::Jpeg, AttachmentType::Png, AttachmentType::Webp];

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
            self::DOCUMENTS => $this->configuredCollection(self::DOCUMENTS),
            self::INVOICE => $this->configuredCollection(self::INVOICE, self::SCAN_TYPES, minFiles: 1),
            self::PAYMENT_PROOF => $this->configuredCollection(self::PAYMENT_PROOF, self::SCAN_TYPES),
            self::COMMENT_IMAGE_UPLOADS => $this->commentUploadCollection(self::COMMENT_IMAGE_UPLOADS, 'images', WorkOrderComment::IMAGE_TYPES),
            self::COMMENT_FILE_UPLOADS => $this->commentUploadCollection(self::COMMENT_FILE_UPLOADS, 'documents'),
        ];
    }

    /**
     * The pending uploads of one kind of comment file, keyed by the kind the
     * upload route names: 'gambar' or 'lampiran'.
     */
    public function commentUploadCollectionFor(string $kind): ?AttachmentCollection
    {
        return match ($kind) {
            WorkOrderComment::IMAGES => $this->attachmentCollections()[self::COMMENT_IMAGE_UPLOADS],
            WorkOrderComment::DOCUMENTS => $this->attachmentCollections()[self::COMMENT_FILE_UPLOADS],
            default => null,
        };
    }

    /**
     * Whether the collection holds files waiting for a comment.
     */
    public static function isCommentUploadCollection(string $collection): bool
    {
        return in_array($collection, [self::COMMENT_IMAGE_UPLOADS, self::COMMENT_FILE_UPLOADS], true);
    }

    /**
     * Pending uploads of comment files: the size and types of the comment's
     * collection, and a limit per uploader (config work_order.comments).
     *
     * @param  'images'|'documents'  $kind
     * @param  list<AttachmentType>|null  $types
     */
    private function commentUploadCollection(string $name, string $kind, ?array $types = null): AttachmentCollection
    {
        return new AttachmentCollection(
            $name,
            maxFiles: config()->integer('work_order.comments.pending_uploads.max_files'),
            maxSizeKb: config()->integer("work_order.comments.{$kind}.max_size_kb"),
            types: $types,
            holdsPendingUploads: true,
        );
    }

    /**
     * A collection with its limits from config/work_order.php.
     *
     * @param  list<AttachmentType>|null  $types
     */
    private function configuredCollection(string $name, ?array $types = null, int $minFiles = 0): AttachmentCollection
    {
        return new AttachmentCollection(
            $name,
            maxFiles: (int) config("work_order.attachments.{$name}.max_files"),
            maxSizeKb: (int) config("work_order.attachments.{$name}.max_size_kb"),
            types: $types,
            minFiles: $minFiles,
        );
    }

    /**
     * The rules of the 'dokumen' collection.
     */
    public function documentsCollection(): AttachmentCollection
    {
        return $this->attachmentCollections()[self::DOCUMENTS];
    }

    /**
     * The department (of a client company) that requests the work (FLOW.md
     * §4). It may change only in Draft, since the number carries its code
     * from the first submission on; kept even if it was deleted later.
     *
     * @return BelongsTo<Department, $this>
     */
    public function requesterDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'requester_department_id')->withTrashed();
    }

    /**
     * The department (of the executor company) the work is addressed to:
     * optional and informational only (FLOW.md v2 §4); it no longer decides
     * who acts or who sees the work order.
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
     * The Admin WO who entered the work order on behalf of the IC contact
     * (FLOW.md §4).
     *
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
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
     * The invoice of a closed work order, once Finance billed it (FLOW.md
     * §10). One per work order for now; instalments would make this a
     * HasMany (FLOW.md §13).
     *
     * @return HasOne<WorkOrderInvoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(WorkOrderInvoice::class);
    }

    /**
     * Daily reports (FLOW.md §7), newest date first.
     *
     * @return HasMany<WorkOrderDailyReport, $this>
     */
    public function dailyReports(): HasMany
    {
        return $this->hasMany(WorkOrderDailyReport::class)->latest('report_date');
    }

    /**
     * The date (display timezone) the work order first entered
     * Pelaksanaan, or null when its status history does not say so.
     */
    public function executionStartedOn(): ?string
    {
        /** @var CarbonInterface|null $entered */
        $entered = $this->statusHistories()->where('to_status', Pelaksanaan::getMorphClass())->value('created_at');

        return $entered === null ? null : ReportCalendar::dateOf($entered);
    }

    /**
     * When Rental last returned the work order from Review Dokumen for
     * revision, or null when it never did.
     */
    public function lastReturnedForRevisionAt(): ?CarbonInterface
    {
        /** @var CarbonInterface|null */
        return $this->statusHistories()
            ->reorder()
            ->where('from_status', ReviewDokumen::getMorphClass())
            ->where('to_status', Pelaksanaan::getMorphClass())
            ->latest('created_at')
            ->latest('id')
            ->value('created_at');
    }

    /**
     * Whether the work order is flagged "Belum lapor" (FLOW.md §7): in
     * Pelaksanaan, today is a working day past its cutoff, and there is no
     * report dated today. The day the work order entered Pelaksanaan (by
     * approval or a return for revision) is exempt. A daily signal of its
     * own, not an overdue basis (FLOW.md §11). Must agree with
     * scopeMissingDailyReport().
     */
    public function isMissingDailyReport(): bool
    {
        if (! ReportCalendar::isReportDueNow() || ! $this->status->equals(Pelaksanaan::class)) {
            return false;
        }

        $today = ReportCalendar::today();

        return ! $this->statusHistories()->where('to_status', Pelaksanaan::getMorphClass())->where('created_at', '>=', DisplayDate::startOfDayUtc($today))->exists()
            && ! $this->dailyReports()->where('report_date', $today)->exists();
    }

    /**
     * The number, or "Draft" until the work order is submitted.
     */
    public function displayNumber(): string
    {
        return $this->number ?? 'Draft';
    }

    /**
     * How messages name the work order: its number, or its quoted title
     * until it is submitted (never "Draft", which says nothing about which).
     */
    public function reference(): string
    {
        return $this->number ?? "'{$this->title}'";
    }

    /**
     * Whether the user may see this work order (FLOW.md v2 §6). Executor
     * company (Unggul) users with work-orders.view see every submitted work
     * order, and drafts too with work-orders.create (Admin WO and the system
     * admin). Client company (IC) users keep the v1 safeguard: at most their
     * own department's work orders, although none are issued an account.
     * Must agree with scopeVisibleTo(), see WorkOrderVisibilityTest.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->isClient()) {
            return $this->requester_department_id === $user->department_id;
        }

        if (! $user->checkPermissionTo(Permission::WorkOrdersView->value)) {
            return false;
        }

        return $this->wasSubmitted() || $user->checkPermissionTo(Permission::WorkOrdersCreate->value);
    }

    /**
     * The payment track of a closed work order (FLOW.md §10), following
     * from its invoice; null for a work order that is not closed.
     */
    public function paymentStatus(): ?PaymentStatus
    {
        return $this->status->equals(Closed::class) ? PaymentStatus::of($this->invoice) : null;
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

        if (! $user->checkPermissionTo(Permission::WorkOrdersView->value)) {
            $query->whereRaw('false');

            return;
        }

        $query->when(
            ! $user->checkPermissionTo(Permission::WorkOrdersCreate->value),
            fn (Builder $query) => $query->whereNotNull('number'),
        );
    }

    /**
     * Only closed work orders in the given payment status (FLOW.md §10).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inPaymentStatus(Builder $query, PaymentStatus $status): void
    {
        $status->constrain($query);
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
     * Whether the work order is late (FLOW.md §11): its status has a
     * deadline() and that date is before today in the display timezone.
     * Must agree with scopeOverdue().
     */
    public function isOverdue(): bool
    {
        $date = $this->status->deadline()?->dateOf($this);

        return $date !== null && $date < DisplayDate::today();
    }

    /**
     * Late work orders (the dashboard's "Terlambat"): in a status with a
     * deadline() whose date is before today in the display timezone, e.g.
     * the target date while Diajukan, Pelaksanaan, or Review Dokumen and the
     * payment due date while Closed and billed (Ditagih). Work orders without that date are never late. With
     * $only, just the ones late against that deadline (the dashboard's
     * breakdown).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overdue(Builder $query, ?WorkOrderDeadline $only = null): void
    {
        $today = DisplayDate::today();
        $groups = array_filter(
            WorkOrderStatus::namesByDeadline(),
            fn (string $deadline): bool => ! $only instanceof WorkOrderDeadline || $deadline === $only->value,
            ARRAY_FILTER_USE_KEY,
        );

        $query->where(function (Builder $query) use ($groups, $today): void {
            foreach ($groups as $deadline => $statuses) {
                $query->orWhere(function (Builder $query) use ($deadline, $statuses, $today): void {
                    $query->whereIn('status', $statuses);
                    WorkOrderDeadline::from($deadline)->constrainPast($query, $today);
                });
            }
        });
    }

    /**
     * Work orders flagged "Belum lapor", see isMissingDailyReport(). Before
     * the cutoff, and on days that are not working days, none are.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function missingDailyReport(Builder $query): void
    {
        if (! ReportCalendar::isReportDueNow()) {
            $query->whereRaw('false');

            return;
        }

        $today = ReportCalendar::today();

        $query->where('status', Pelaksanaan::getMorphClass())
            ->whereDoesntHave('statusHistories', fn (Builder $history) => $history
                ->where('to_status', Pelaksanaan::getMorphClass())
                ->where('created_at', '>=', DisplayDate::startOfDayUtc($today)))
            ->whereDoesntHave('dailyReports', fn (Builder $report) => $report->where('report_date', $today));
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
