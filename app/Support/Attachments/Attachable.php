<?php

namespace App\Support\Attachments;

use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Spatie\MediaLibrary\HasMedia;

/**
 * A model that can have attachments. Implement it with the HasAttachments
 * trait, and give the model's policy addAttachment and deleteAttachment
 * abilities; viewing and downloading use the policy's view ability.
 */
interface Attachable extends HasMedia
{
    /**
     * The collections this model accepts, keyed by name.
     *
     * @return array<string, AttachmentCollection>
     */
    public function attachmentCollections(): array;

    public function attachmentCollection(string $name): ?AttachmentCollection;

    /**
     * @return Collection<int, Media>
     */
    public function attachmentsIn(string $collection): Collection;
}
