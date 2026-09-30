<?php

namespace App\Models;

use App\Concerns\HasAttachments;
use App\Enums\AttachmentType;
use App\Support\Attachments\Attachable;
use App\Support\Attachments\AttachmentCollection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The BAST template (FLOW.md §9). There is exactly one (a unique index
 * holds it to one row): its draft is edited and previewed, and publishing
 * copies the draft into an immutable BastTemplateVersion. Its images (the
 * letterhead, PNG or JPEG) are attachments of the template, shown in the
 * draft through their attachment route and copied into each version.
 * Written only through the BastTemplates actions, which log to it.
 *
 * @property int $id
 * @property string $draft_html
 * @property int|null $draft_updated_by
 * @property CarbonImmutable|null $draft_updated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $draftEditor
 * @property-read Collection<int, BastTemplateVersion> $versions
 * @property-read BastTemplateVersion|null $latestVersion
 */
class BastTemplate extends Model implements Attachable
{
    use HasAttachments;

    /**
     * The template's images, e.g. the letterhead.
     */
    public const string IMAGES = 'gambar';

    /**
     * The template, created empty on first use.
     */
    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }

    /**
     * @return array<string, AttachmentCollection>
     */
    public function attachmentCollections(): array
    {
        return [
            self::IMAGES => new AttachmentCollection(
                self::IMAGES,
                maxFiles: config()->integer('work_order.bast.images.max_files'),
                maxSizeKb: config()->integer('work_order.bast.images.max_size_kb'),
                types: [AttachmentType::Png, AttachmentType::Jpeg],
                maxImageSide: config()->integer('work_order.bast.images.max_side_px'),
            ),
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function draftEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_updated_by')->withTrashed();
    }

    /**
     * Published versions, newest first.
     *
     * @return HasMany<BastTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(BastTemplateVersion::class)->orderByDesc('version');
    }

    /**
     * @return HasOne<BastTemplateVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(BastTemplateVersion::class)->latestOfMany('version');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'draft_updated_at' => 'datetime',
        ];
    }
}
