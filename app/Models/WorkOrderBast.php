<?php

namespace App\Models;

use App\Concerns\HasAttachments;
use App\Enums\AttachmentType;
use App\Support\Attachments\Attachable;
use App\Support\Attachments\AttachmentCollection;
use Carbon\CarbonImmutable;
use Database\Factories\WorkOrderBastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * The BAST of a work order (FLOW.md §8): numbered and generated as a draft
 * PDF when Rental submits it (Review Dokumen → Approval BAST), and generated
 * again as the final PDF, with the Direktur's name and approval time, when
 * approved (Approval BAST → BAST Disetujui). It keeps the template version
 * it was generated from, and the SHA-256 of the final PDF. Written only
 * through GenerateBast and ApproveBast, which log to the work order.
 *
 * A database trigger refuses deleting a BAST, changing its number, version,
 * or submission, and any change once approved; another refuses changing or
 * deleting the final PDF's media row. Its files follow the work order's
 * visibility and are never shown to client company users.
 *
 * @property int $id
 * @property int $work_order_id
 * @property string $number
 * @property int $bast_template_version_id
 * @property int $submitted_by
 * @property CarbonImmutable $submitted_at
 * @property int|null $approved_by
 * @property string|null $approver_name
 * @property CarbonImmutable|null $approved_at
 * @property string|null $final_sha256
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WorkOrder $workOrder
 * @property-read BastTemplateVersion $templateVersion
 * @property-read User $submitter
 * @property-read User|null $approver
 * @property-read Media|null $draftFile
 * @property-read Media|null $finalFile
 */
class WorkOrderBast extends Model implements Attachable
{
    /** @use HasFactory<WorkOrderBastFactory> */
    use HasAttachments, HasFactory;

    /**
     * The draft PDF, generated when Rental submits the BAST.
     */
    public const string DRAFT = 'draf';

    /**
     * The final PDF, generated with the Direktur's approval. Immutable.
     */
    public const string FINAL = 'final';

    /**
     * @return array<string, AttachmentCollection>
     */
    public function attachmentCollections(): array
    {
        return collect([self::DRAFT, self::FINAL])
            ->mapWithKeys(fn (string $name): array => [$name => new AttachmentCollection(
                $name,
                maxFiles: 1,
                maxSizeKb: config()->integer('work_order.bast.pdf_max_size_kb'),
                types: [AttachmentType::Pdf],
                loggedByRecord: true,
            )])
            ->all();
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    /**
     * @return BelongsTo<BastTemplateVersion, $this>
     */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(BastTemplateVersion::class, 'bast_template_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    /**
     * @return MorphOne<Media, $this>
     */
    public function draftFile(): MorphOne
    {
        return $this->morphOne(Media::class, 'model')->where('collection_name', self::DRAFT);
    }

    /**
     * @return MorphOne<Media, $this>
     */
    public function finalFile(): MorphOne
    {
        return $this->morphOne(Media::class, 'model')->where('collection_name', self::FINAL);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
