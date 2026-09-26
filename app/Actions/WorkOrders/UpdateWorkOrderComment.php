<?php

namespace App\Actions\WorkOrders;

use App\Concerns\GuardsWorkOrderComments;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrderComment;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a comment's text within the edit window and marks it edited.
 * Who may edit (the author) is the policy's decision.
 */
class UpdateWorkOrderComment
{
    use GuardsWorkOrderComments;

    /**
     * @throws CommentNotAllowed when the work order is read-only, the comment was deleted, or the edit window has passed
     */
    public function handle(WorkOrderComment $comment, User $user, string $body): WorkOrderComment
    {
        return DB::transaction(function () use ($comment, $user, $body): WorkOrderComment {
            $workOrder = $this->lockAcceptingComments($comment->workOrder);
            $locked = $this->lockLiveComment($comment);
            $this->ensureWithinEditWindow($locked);

            if ($locked->body === $body) {
                return $locked;
            }

            $locked->body = $body;
            $locked->edited_at = now();
            $locked->save();

            $this->logCommentEvent($workOrder, $locked, $user, AuditEvent::CommentEdited);

            return $locked;
        });
    }
}
