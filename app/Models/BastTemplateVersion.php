<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BastTemplateVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A published version of the BAST template (FLOW.md §9): its sanitized HTML
 * and the images it shows (base64, so later changes to the template's
 * uploads never alter it). Immutable once published: only is_active
 * changes, and exactly one version is active (a partial unique index).
 * A database trigger enforces both; this model refuses them first.
 *
 * @property int $id
 * @property int $bast_template_id
 * @property int $version
 * @property string $html
 * @property array<string, array{name: string, mime: string, data: string}> $images
 * @property int|null $published_by
 * @property CarbonImmutable $published_at
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BastTemplate $template
 * @property-read User|null $publisher
 */
class BastTemplateVersion extends Model
{
    /** @use HasFactory<BastTemplateVersionFactory> */
    use HasFactory;

    /**
     * The columns that may change after publishing.
     */
    private const array MUTABLE = ['is_active', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if (array_diff(array_keys($version->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('A published BAST template version cannot be changed.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('A published BAST template version cannot be deleted.');
        });
    }

    /**
     * The active version, if any.
     */
    public static function active(): ?self
    {
        return self::query()->where('is_active', true)->first();
    }

    /**
     * @return BelongsTo<BastTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(BastTemplate::class, 'bast_template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by')->withTrashed();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'images' => 'array',
            'published_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
