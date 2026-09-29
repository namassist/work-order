<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use App\States\WorkOrder\WorkOrderStatus;
use Illuminate\Auth\Access\Response;

/**
 * Every check on a single work order first requires that the user can see
 * it (WorkOrder::isVisibleTo(), FLOW.md §6). Otherwise the answer is 404, so
 * drafts, and for client company users other departments' work orders, are
 * not confirmed to exist.
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
     * Determine whether the user can open one of the work order's files:
     * whoever may view it, except a comment file still waiting for its
     * comment, which only its uploader sees.
     */
    public function viewAttachment(User $user, WorkOrder $workOrder, Media $media): Response
    {
        $view = $this->view($user, $workOrder);

        if ($view->denied() || ! WorkOrder::isCommentUploadCollection((string) $media->collection_name)) {
            return $view;
        }

        return $media->uploaded_by === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Determine whether the user can list soft-deleted work orders.
     */
    public function viewTrashed(User $user): bool
    {
        return $user->checkPermissionTo(Permission::WorkOrdersRestore->value);
    }

    /**
     * Determine whether the user can create work orders (FLOW.md v2 §4):
     * an Admin WO, entering them on behalf of an IC department and contact.
     * Never a client company user (work-orders.create is internal-only).
     * Needs work-orders.view too, or the new draft would be invisible to its
     * own creator.
     */
    public function create(User $user): bool
    {
        return ! $user->isClient()
            && $user->checkPermissionTo(Permission::WorkOrdersCreate->value)
            && $this->viewAny($user);
    }

    /**
     * Determine whether the user can edit the work order: an Admin WO
     * (work-orders.update), while its status isEditable() (Draft and Ditolak).
     */
    public function update(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible(
            $user,
            $workOrder,
            $workOrder->status->isEditable() && $this->holds($user, Permission::WorkOrdersUpdate),
        );
    }

    /**
     * Determine whether the user can move the work order to the given status:
     * the holder of the permission its current status names for that change
     * (WorkOrderStatus::transitions(), FLOW.md §5.1, §5.2). TransitionWorkOrder
     * checks the status again under a lock.
     */
    public function transition(User $user, WorkOrder $workOrder, string $to): Response
    {
        $transition = $workOrder->status->transitionFor($to);

        return $this->ifVisible($user, $workOrder, $transition !== null && $this->holds($user, $transition->permission));
    }

    /**
     * Determine whether the user makes any status change at all, so one it
     * cannot make from the current status (e.g. from a page loaded before
     * someone else changed it) is answered with a validation message rather
     * than a 403.
     */
    public function changeStatus(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible(
            $user,
            $workOrder,
            array_any(WorkOrderStatus::transitionPermissions(), fn (Permission $permission): bool => $this->holds($user, $permission)),
        );
    }

    /**
     * Determine whether the user can issue and correct the invoice of a
     * closed work order (FLOW.md §10): Finance, with work-orders.bill.
     * Whether the payment track allows it right now is checked by
     * BillWorkOrder and CorrectInvoice under a lock, which refuse with a
     * message, since an open page can go stale.
     */
    public function bill(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible($user, $workOrder, $this->holds($user, Permission::WorkOrdersBill));
    }

    /**
     * Determine whether the user can confirm the payment of a billed work
     * order (FLOW.md §10): Finance, with work-orders.confirm-payment. The
     * payment track and segregation of duties are checked by
     * ConfirmWorkOrderPayment under a lock.
     */
    public function confirmPayment(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible($user, $workOrder, $this->holds($user, Permission::WorkOrdersConfirmPayment));
    }

    /**
     * Determine whether the user can attach a file to the collection: the
     * holder of the permission the status names for it in
     * attachmentPermissions() (FLOW.md §5.3).
     */
    public function addAttachment(User $user, WorkOrder $workOrder, string $collection): Response
    {
        return $this->ifVisible($user, $workOrder, $this->mayChangeAttachments($user, $workOrder, $collection));
    }

    /**
     * Determine whether the user can remove an attachment from the work
     * order: same rule as adding. Only an Admin WO revising the request
     * (collections guarded by work-orders.update) removes files someone else
     * uploaded; everyone else removes only their own uploads, since the
     * request's documents are part of what was approved.
     */
    public function deleteAttachment(User $user, WorkOrder $workOrder, Media $media): Response
    {
        $collection = (string) $media->collection_name;

        return $this->ifVisible(
            $user,
            $workOrder,
            $this->mayChangeAttachments($user, $workOrder, $collection)
                && ($workOrder->status->attachmentPermissionFor($collection) === Permission::WorkOrdersUpdate || $media->uploaded_by === $user->id),
        );
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

    private function mayChangeAttachments(User $user, WorkOrder $workOrder, string $collection): bool
    {
        $permission = $workOrder->status->attachmentPermissionFor($collection);

        return $permission !== null && $this->holds($user, $permission);
    }

    /**
     * Whether the user holds a permission that acts on work orders. Client
     * company users never do: every such permission is internal-only
     * (FLOW.md §2), which User::hasPermissionTo() enforces too.
     */
    private function holds(User $user, Permission $permission): bool
    {
        return ! $user->isClient() && $user->checkPermissionTo($permission->value);
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
