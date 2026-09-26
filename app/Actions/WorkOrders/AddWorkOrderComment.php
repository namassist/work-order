<?php

namespace App\Actions\WorkOrders;

use App\Concerns\GuardsWorkOrderComments;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use Illuminate\Support\Facades\DB;

/**
 * Adds a plain-text comment to a work order that still accepts comments and
 * logs it on the work order (without the text).
 */
class AddWorkOrderComment
{
    use GuardsWorkOrderComments;

    /**
     * @throws CommentNotAllowed when the work order's status no longer accepts comments
     */
    public function handle(WorkOrder $workOrder, User $author, string $body): WorkOrderComment
    {
        return DB::transaction(function () use ($workOrder, $author, $body): WorkOrderComment {
            $locked = $this->lockAcceptingComments($workOrder);

            $comment = new WorkOrderComment(['body' => $body]);
            $comment->workOrder()->associate($locked);
            $comment->author()->associate($author);
            $comment->save();

            $this->logCommentEvent($locked, $comment, $author, AuditEvent::CommentAdded);

            return $comment;
        });
    }
}
