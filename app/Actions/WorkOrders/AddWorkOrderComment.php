<?php

namespace App\Actions\WorkOrders;

use App\Concerns\GuardsWorkOrderComments;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use App\Support\Comments\CommentContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a rich-text comment to a work order that still accepts comments: the
 * body sanitized (CommentHtml), the author's pending uploads it shows or
 * lists claimed as its files, and logged on the work order (file names, not
 * the text).
 */
class AddWorkOrderComment
{
    use GuardsWorkOrderComments;

    /**
     * @param  list<string>  $documentUuids  the author's pending document uploads to attach
     *
     * @throws CommentNotAllowed when the work order's status no longer accepts comments
     * @throws ValidationException when the body or files are refused
     */
    public function handle(WorkOrder $workOrder, User $author, string $body, array $documentUuids = []): WorkOrderComment
    {
        return DB::transaction(function () use ($workOrder, $author, $body, $documentUuids): WorkOrderComment {
            $locked = $this->lockAcceptingComments($workOrder);
            $content = CommentContent::resolve($locked, null, $author, $body, $documentUuids);

            $comment = new WorkOrderComment(['body' => $content->body->html, 'body_text' => $content->body->text]);
            $comment->workOrder()->associate($locked);
            $comment->author()->associate($author);
            $comment->save();

            $content->claimFor($comment);

            $this->logCommentEvent($locked, $comment, $author, AuditEvent::CommentAdded, $content->changesFiles() ? $content->fileNames() : null);

            return $comment;
        });
    }
}
