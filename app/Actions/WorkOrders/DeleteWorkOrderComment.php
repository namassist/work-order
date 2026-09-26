<?php

namespace App\Actions\WorkOrders;

use App\Concerns\GuardsWorkOrderComments;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrderComment;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a comment within the edit window. The timeline keeps its
 * place as "Komentar dihapus". Who may delete (the author) is the policy's
 * decision.
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

            $locked->delete();

            $this->logCommentEvent($workOrder, $locked, $user, AuditEvent::CommentDeleted);
        });
    }
}
