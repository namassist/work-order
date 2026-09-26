<?php

namespace App\Actions\Attachments;

use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\Media;
use App\Support\Attachments\Attachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an attachment and its file, logged on the parent.
 */
class RemoveAttachment
{
    use LogsAuditChanges;

    public function handle(Model&Attachable $parent, Media $media): void
    {
        DB::transaction(function () use ($parent, $media): void {
            $this->logAuditChange($parent, AuditEvent::AttachmentRemoved, ['lampiran' => $media->name, 'ukuran' => $media->size], []);

            $media->delete();
        });
    }
}
