<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrderComment;
use Illuminate\Auth\Access\Response;

/**
 * The files of a comment (its inline images and documents). Every other
 * comment check is on WorkOrderPolicy, with the work order as its subject.
 */
class WorkOrderCommentPolicy
{
    public function __construct(private readonly WorkOrderPolicy $workOrders) {}

    /**
     * Determine whether the user can open one of the comment's files:
     * whoever may view its work order (FLOW.md §6), 404 otherwise.
     */
    public function viewAttachment(User $user, WorkOrderComment $comment, Media $media): Response
    {
        $workOrder = $comment->workOrder;

        if ($workOrder->trashed()) {
            return Response::denyAsNotFound();
        }

        return $this->workOrders->view($user, $workOrder);
    }

    /**
     * Comment files are added only through the comment actions, which claim
     * the author's pending uploads.
     */
    public function addAttachment(User $user, WorkOrderComment $comment, string $collection): Response
    {
        return Response::denyAsNotFound();
    }

    /**
     * Comment files are removed only through the comment actions: by editing
     * the comment or deleting it.
     */
    public function deleteAttachment(User $user, WorkOrderComment $comment, Media $media): Response
    {
        return Response::deny();
    }
}
