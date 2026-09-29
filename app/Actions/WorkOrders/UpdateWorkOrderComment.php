<?php

namespace App\Actions\WorkOrders;

use App\Concerns\GuardsWorkOrderComments;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrderComment;
use App\Support\Comments\CommentContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces a comment's body and files within the edit window and marks it
 * edited. Files it no longer shows are deleted after the commit. Who may
 * edit (the author) is the policy's decision.
 */
class UpdateWorkOrderComment
{
    use GuardsWorkOrderComments;

    /**
     * @param  list<string>  $documentUuids  the documents to keep or attach
     *
     * @throws CommentNotAllowed when the work order is read-only, the comment was deleted, or the edit window has passed
     * @throws ValidationException when the body or files are refused
     */
    public function handle(WorkOrderComment $comment, User $user, string $body, array $documentUuids = []): WorkOrderComment
    {
        return DB::transaction(function () use ($comment, $user, $body, $documentUuids): WorkOrderComment {
            $workOrder = $this->lockAcceptingComments($comment->workOrder);
            $locked = $this->lockLiveComment($comment);
            $this->ensureWithinEditWindow($locked);

            $content = CommentContent::resolve($workOrder, $locked, $user, $body, $documentUuids);

            if ($locked->body === $content->body->html && ! $content->changesFiles()) {
                return $locked;
            }

            $locked->body = $content->body->html;
            $locked->body_text = $content->body->text;
            $locked->edited_at = now();
            $locked->save();

            $content->claimFor($locked);

            $this->logCommentEvent($workOrder, $locked, $user, AuditEvent::CommentEdited, $content->changesFiles() ? $content->fileNames() : null);

            return $locked;
        });
    }
}
