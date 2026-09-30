<?php

namespace App\Models;

use App\Concerns\HasAttachments;
use App\Enums\AttachmentType;
use App\Support\Attachments\Attachable;
use App\Support\Attachments\AttachmentCollection;
use App\Support\DailyReports\ReportCalendar;
use Carbon\CarbonImmutable;
use Database\Factories\WorkOrderDailyReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A daily progress report on a work order in Pelaksanaan (FLOW.md §7): one
 * per work order per date (report_date, a display-timezone calendar date),
 * a short plain-text note, and at least one file or link. Its files are
 * attachments of the report (XLSX and PDF) and follow the work order's
 * visibility. Written only through AddDailyReport and UpdateDailyReport,
 * which log to the work order's audit trail; saving one bumps the work
 * order's updated_at, its last activity. Reports are edited, never deleted.
 *
 * @property int $id
 * @property int $work_order_id
 * @property CarbonImmutable $report_date
 * @property string $note
 * @property list<string> $links
 * @property int $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WorkOrder $workOrder
 * @property-read User $reporter
 * @property-read User|null $editor
 */
#[Fillable(['report_date', 'note', 'links'])]
#[Touches(['workOrder'])]
class WorkOrderDailyReport extends Model implements Attachable
{
    /** @use HasFactory<WorkOrderDailyReportFactory> */
    use HasAttachments, HasFactory;

    /**
     * The report's files: the timesheet itself, kept outside the application.
     */
    public const string FILES = 'berkas';

    /**
     * Types a report file may have: Excel, and PDF for scanned or signed sheets.
     *
     * @var list<AttachmentType>
     */
    public const array FILE_TYPES = [AttachmentType::Xlsx, AttachmentType::Pdf];

    /**
     * The model's default values for attributes, matching the columns' defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'links' => '[]',
    ];

    /**
     * @return array<string, AttachmentCollection>
     */
    public function attachmentCollections(): array
    {
        return [
            self::FILES => new AttachmentCollection(
                self::FILES,
                maxFiles: config()->integer('work_order.daily_reports.files.max_files'),
                maxSizeKb: config()->integer('work_order.daily_reports.files.max_size_kb'),
                types: self::FILE_TYPES,
                loggedByRecord: true,
            ),
        ];
    }

    /**
     * The report's files, oldest first.
     *
     * @return MorphMany<Media, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(Media::class, 'model')
            ->where('collection_name', self::FILES)
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
     * Who posted the report, even if they were deleted later.
     *
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * Who last edited the report: any holder of work-orders.report, since
     * there is one report per work order per day.
     *
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    /**
     * Whether the report may still be edited: until the end of the day it
     * was created, plus work_order.daily_reports.edit_extra_days. The work
     * order must also still be in Pelaksanaan (checked by UpdateDailyReport).
     */
    public function isWithinEditWindow(): bool
    {
        return now()->lessThanOrEqualTo(ReportCalendar::editableUntil($this->created_at));
    }

    /**
     * The report as the audit trail shows it: its date, note, links, and
     * the names of $files (its files at that moment), never file content.
     *
     * @param  Collection<int, Media>  $files
     * @return array{tanggal_laporan: string, catatan: string, tautan: string|null, lampiran: string|null}
     */
    public function auditValues(Collection $files): array
    {
        return [
            'tanggal_laporan' => $this->report_date->toDateString(),
            'catatan' => $this->note,
            'tautan' => $this->links === [] ? null : implode("\n", $this->links),
            'lampiran' => $files->isEmpty() ? null : $files->map(fn (Media $media): string => $media->name)->implode(', '),
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
            'report_date' => 'date',
            'links' => 'array',
        ];
    }
}
