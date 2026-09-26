<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use Illuminate\Auth\Access\Response;

/**
 * Every check on a single work order first requires that the user can see
 * it (their department's, or work-orders.view-all). Otherwise the answer is
 * 404, so other departments' work orders are not confirmed to exist.
 */
class WorkOrderPolicy
{
    /**
     * Determine whether the user can list work orders.
     */
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrdersView->value);
    }

    /**
     * Determine whether the user sees work orders of every department.
     */
    public function viewAllDepartments(User $user): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrdersViewAll->value);
    }

    /**
     * Determine whether the user can download the work order list as a
     * spreadsheet. What it holds is limited like the list itself.
     */
    public function export(User $user): bool
    {
        return $this->viewAny($user) && $user->checkPermissionTo(Permission::WorkOrdersExport->value);
    }

    /**
     * Determine whether the user can view the work order.
     */
    public function view(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible($user, $workOrder, $this->viewAny($user));
    }

    /**
     * Determine whether the user can list soft-deleted work orders.
     */
    public function viewTrashed(User $user): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrdersRestore->value);
    }

    /**
     * Determine whether the user can create work orders. A work order belongs
     * to its requester's department, so the requester needs one.
     */
    public function create(User $user): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrdersCreate->value) && $user->department_id !== null;
    }

    /**
     * Determine whether the user can edit the work order (drafts only).
     */
    public function update(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible(
            $user,
            $workOrder,
            $user->checkPermissionTo(Permission::WorkOrdersUpdate->value) && $workOrder->status->isEditable(),
        );
    }

    /**
     * Determine whether the user can change the work order's status.
     * PROVISIONAL: anyone who may update work orders, until the real flow
     * assigns transitions to roles.
     */
    public function transition(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible($user, $workOrder, $user->checkPermissionTo(Permission::WorkOrdersUpdate->value));
    }

    /**
     * Determine whether the user can attach a file to the work order.
     * PROVISIONAL: drafts only, by anyone who may update work orders.
     */
    public function addAttachment(User $user, WorkOrder $workOrder, string $collection): Response
    {
        return $this->ifVisible($user, $workOrder, $this->mayChangeAttachments($user, $workOrder));
    }

    /**
     * Determine whether the user can remove an attachment from the work
     * order. PROVISIONAL: same rule as adding.
     */
    public function deleteAttachment(User $user, WorkOrder $workOrder, Media $media): Response
    {
        return $this->ifVisible($user, $workOrder, $this->mayChangeAttachments($user, $workOrder));
    }

    /**
     * Determine whether the user can comment on the work order. Anyone who
     * can view it reads its comments; writing needs work-orders.comment.
     * Whether the status still accepts comments is checked by the comment
     * actions, which refuse with a message.
     */
    public function addComment(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifViewable($user, $workOrder, $user->checkPermissionTo(Permission::WorkOrdersComment->value));
    }

    /**
     * Determine whether the user can edit the comment: only its author. The
     * edit window and status are checked by the action.
     */
    public function updateComment(User $user, WorkOrder $workOrder, WorkOrderComment $comment): Response
    {
        return $this->ifViewable($user, $workOrder, $this->isCommentAuthor($user, $workOrder, $comment));
    }

    /**
     * Determine whether the user can delete the comment: same rule as editing.
     */
    public function deleteComment(User $user, WorkOrder $workOrder, WorkOrderComment $comment): Response
    {
        return $this->ifViewable($user, $workOrder, $this->isCommentAuthor($user, $workOrder, $comment));
    }

    /**
     * Determine whether the user can soft-delete the work order. Only drafts
     * are deleted; the controller refuses others with a message.
     */
    public function delete(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible($user, $workOrder, $user->checkPermissionTo(Permission::WorkOrdersDelete->value));
    }

    /**
     * Determine whether the user can restore the work order.
     */
    public function restore(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible($user, $workOrder, $this->viewTrashed($user));
    }

    /**
     * Permanent deletion is not offered anywhere in the application.
     */
    public function forceDelete(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }

    private function mayChangeAttachments(User $user, WorkOrder $workOrder): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrdersUpdate->value) && $workOrder->status->isEditable();
    }

    private function isCommentAuthor(User $user, WorkOrder $workOrder, WorkOrderComment $comment): bool
    {
        return $comment->work_order_id === $workOrder->id
            && $comment->user_id === $user->id
            && $user->checkPermissionTo(Permission::WorkOrdersComment->value);
    }

    /**
     * Builds on view(), so whoever may view the work order (visibility plus
     * work-orders.view) is who may also be granted $allowed.
     */
    private function ifViewable(User $user, WorkOrder $workOrder, bool $allowed): Response
    {
        $view = $this->view($user, $workOrder);

        if ($view->denied()) {
            return $view;
        }

        return $allowed ? Response::allow() : Response::deny();
    }

    private function ifVisible(User $user, WorkOrder $workOrder, bool $allowed): Response
    {
        if (! $workOrder->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        return $allowed ? Response::allow() : Response::deny();
    }
}
