<?php

namespace App\Actions\Attachments;

use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\Media;
use App\Support\Attachments\Attachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an attachment and its file, logged on the parent. The removal
 * counts as activity on the parent, so its updated_at is bumped.
 */
class RemoveAttachment
{
    use LogsAuditChanges;

    public function handle(Model&Attachable $parent, Media $media): void
    {
        DB::transaction(function () use ($parent, $media): void {
            if ($parent->attachmentCollection((string) $media->collection_name)?->loggedByRecord !== true) {
                $this->logAuditChange($parent, AuditEvent::AttachmentRemoved, ['lampiran' => $media->name, 'ukuran' => $media->size], []);
            }

            $media->delete();

            // A query, not $parent->touch(): that would also save any unsaved change on $parent.
            $parent->newQuery()->whereKey($parent->getKey())->touch();
        });
    }
}
