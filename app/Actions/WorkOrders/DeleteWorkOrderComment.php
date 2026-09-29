<?php

namespace App\Actions\WorkOrders;

use App\Concerns\GuardsWorkOrderComments;
use App\Enums\AuditEvent;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrderComment;
use App\Support\Comments\CommentContent;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a comment within the edit window. The timeline keeps its
 * place as "Komentar dihapus"; its files are deleted after the commit, and
 * their names stay in the audit log. Who may delete (the author) is the
 * policy's decision.
 */
class DeleteWorkOrderComment
{
    use GuardsWorkOrderComments;

    /**
     * @throws CommentNotAllowed when the work order is read-only, the comment was deleted, or the edit window has passed
     */
    public function handle(WorkOrderComment $comment, User $user): void
    {
        DB::transaction(function () use ($comment, $user): void {
            $workOrder = $this->lockAcceptingComments($comment->workOrder);
            $locked = $this->lockLiveComment($comment);
            $this->ensureWithinEditWindow($locked);

            $files = [
                ...$locked->attachmentsIn(WorkOrderComment::IMAGES)->all(),
                ...$locked->attachmentsIn(WorkOrderComment::DOCUMENTS)->all(),
            ];

            $locked->delete();
            CommentContent::deleteAfterCommit($files);

            $this->logCommentEvent($workOrder, $locked, $user, AuditEvent::CommentDeleted, $files === [] ? null : [
                'old' => array_map(fn (Media $media): string => $media->name, $files),
                'new' => [],
            ]);
        });
    }
}
