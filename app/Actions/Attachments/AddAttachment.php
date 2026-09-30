<?php

namespace App\Actions\Attachments;

use App\Concerns\LogsAuditChanges;
use App\Enums\AttachmentType;
use App\Enums\AuditEvent;
use App\Models\Media;
use App\Models\User;
use App\Support\Attachments\Attachable;
use App\Support\Attachments\AttachmentCollection;
use App\Support\Attachments\AttachmentTypeDetector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores a validated upload in a collection of its parent: under a random
 * name, with the type detected from its content, and logged on the parent.
 * The collection's file limit is checked with the parent locked, so two
 * uploads at once cannot exceed it; a refused upload stores nothing. The
 * upload counts as activity on the parent, so its updated_at is bumped,
 * except in a collection of pending uploads, which is neither logged nor
 * touched until a record claims the file.
 */
class AddAttachment
{
    use LogsAuditChanges;

    public function __construct(private readonly AttachmentTypeDetector $detector) {}

    /**
     * @param  string  $errorKey  the input the limit error is reported on
     *
     * @throws ValidationException when the file type is not allowed or the collection is full
     */
    public function handle(Model&Attachable $parent, AttachmentCollection $collection, UploadedFile $file, User $uploader, string $errorKey = 'file'): Media
    {
        $type = $this->detector->detect((string) $file->getRealPath());

        if (! $type instanceof AttachmentType || ! $collection->accepts($type)) {
            throw ValidationException::withMessages([$errorKey => __('Jenis berkas tidak diizinkan. Gunakan :types.', ['types' => $collection->typeList()])]);
        }

        return DB::transaction(function () use ($parent, $collection, $file, $uploader, $errorKey, $type): Media {
            $parent->newQuery()->whereKey($parent->getKey())->lockForUpdate()->first();

            $existing = $parent->media()->where('collection_name', $collection->name)
                ->when($collection->holdsPendingUploads, fn ($query) => $query->where('uploaded_by', $uploader->id));

            if ($existing->count() >= $collection->maxFiles) {
                throw ValidationException::withMessages([$errorKey => $collection->holdsPendingUploads
                    ? __('Unggahan komentar yang belum dikirim sudah mencapai batas :max berkas.', ['max' => $collection->maxFiles])
                    : __('Lampiran sudah mencapai batas :max berkas.', ['max' => $collection->maxFiles])]);
            }

            /** @var Media $media */
            $media = $parent->addMedia($file)
                ->usingName($this->displayName($file->getClientOriginalName(), $type->extension()))
                ->usingFileName(Str::uuid()->toString().'.'.$type->extension())
                ->withProperties(['mime_type' => $type->value, 'uploaded_by' => $uploader->id])
                ->toMediaCollection($collection->name);

            // A rollback here or in the caller's transaction (e.g. creating a
            // WO with several documents) undoes the row but not the file.
            $disk = $media->disk;
            $directory = dirname($media->getPathRelativeToRoot());
            DB::afterRollBack(fn () => Storage::disk($disk)->deleteDirectory($directory));

            if ($collection->holdsPendingUploads) {
                return $media;
            }

            // A query, not $parent->touch(): that would also save any unsaved change on $parent.
            $parent->newQuery()->whereKey($parent->getKey())->touch();

            if (! $collection->loggedByRecord) {
                $this->logAuditChange($parent, AuditEvent::AttachmentAdded, [], ['lampiran' => $media->name, 'ukuran' => $media->size]);
            }

            return $media;
        });
    }

    /**
     * The original filename, for display only: no path, no control
     * characters, at most 255 characters.
     */
    private function displayName(string $original, string $fallbackExtension): string
    {
        $name = (string) preg_replace('#\p{C}+#u', '', $original);
        $name = trim(Str::afterLast(str_replace('\\', '/', $name), '/'));

        return $name === '' ? 'lampiran.'.$fallbackExtension : mb_substr($name, -255);
    }
}
