<?php

namespace App\Concerns;

use App\Models\Media;
use App\Support\Attachments\Attachable;
use App\Support\Attachments\AttachmentCollection;
use Illuminate\Database\Eloquent\Collection;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Attachments for a model implementing Attachable. The model only declares
 * attachmentCollections(); files always go to the private attachments disk.
 *
 * Soft-deleting the model keeps its files; only a force delete removes them.
 *
 * @phpstan-require-implements Attachable
 */
trait HasAttachments
{
    use InteractsWithMedia;

    public function attachmentCollection(string $name): ?AttachmentCollection
    {
        return $this->attachmentCollections()[$name] ?? null;
    }

    public function registerMediaCollections(): void
    {
        foreach ($this->attachmentCollections() as $collection) {
            $this->addMediaCollection($collection->name)->useDisk((string) config('media-library.disk_name'));
        }
    }

    /**
     * The collection's attachments with their uploaders, oldest first.
     *
     * @return Collection<int, Media>
     */
    public function attachmentsIn(string $collection): Collection
    {
        return Media::query()
            ->whereMorphedTo('model', $this)
            ->where('collection_name', $collection)
            ->with('uploader')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
