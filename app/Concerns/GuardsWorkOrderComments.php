<?php

namespace App\Concerns;

use App\Actions\WorkOrders\CommentNotAllowed;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;

/**
 * Shared by the work order comment actions: the status and edit-window
 * rules, and the audit entry, which names the comment but never its text.
 */
trait GuardsWorkOrderComments
{
    /**
     * Re-read the work order under a shared lock, so a cancellation running
     * at the same moment waits for the comment or sees it, and refuse when
     * its status no longer accepts comments. Call inside a transaction.
     *
     * @throws CommentNotAllowed
     */
    protected function lockAcceptingComments(WorkOrder $workOrder): WorkOrder
    {
        $locked = WorkOrder::query()->sharedLock()->findOrFail($workOrder->id);

        if (! $locked->status->acceptsComments()) {
            throw CommentNotAllowed::readOnly($locked);
        }

        return $locked;
    }

    /**
     * Re-read the comment under an update lock, so an edit and a delete of
     * the same comment run one after the other, and refuse one that was
     * deleted since the page loaded it. Call inside a transaction.
     *
     * @throws CommentNotAllowed
     */
    protected function lockLiveComment(WorkOrderComment $comment): WorkOrderComment
    {
        $locked = WorkOrderComment::withTrashed()->lockForUpdate()->findOrFail($comment->id);

        if ($locked->trashed()) {
            throw CommentNotAllowed::alreadyDeleted();
        }

        return $locked;
    }

    /**
     * @throws CommentNotAllowed
     */
    protected function ensureWithinEditWindow(WorkOrderComment $comment): void
    {
        if (! $comment->isWithinEditWindow()) {
            throw CommentNotAllowed::editWindowPassed();
        }
    }

    protected function logCommentEvent(WorkOrder $workOrder, WorkOrderComment $comment, User $user, AuditEvent $event): void
    {
        activity()
            ->performedOn($workOrder)
            ->causedBy($user)
            ->event($event->value)
            ->withProperties(['komentar_id' => $comment->id])
            ->log($event->value);
    }
}
