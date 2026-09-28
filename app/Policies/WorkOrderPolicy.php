<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\WorkOrderSide;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use App\States\WorkOrder\WorkOrderStatus;
use Illuminate\Auth\Access\Response;

/**
 * Every check on a single work order first requires that the user can see
 * it (WorkOrder::isVisibleTo(), FLOW.md §6). Otherwise the answer is 404, so
 * work orders of other departments and companies are not confirmed to exist.
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
     * Determine whether the user sees the submitted work orders of every
     * department (never a client company user, see User::hasPermissionTo()).
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
     * Determine whether the user can create work orders (FLOW.md §4): an IC
     * user for their own department, or an executor user (koordinator) on
     * behalf of an IC department.
     */
    public function create(User $user): bool
    {
        return $user->isClient()
            ? $user->checkPermissionTo(Permission::WorkOrdersCreate->value)
            : $this->createOnBehalf($user);
    }

    /**
     * Determine whether the user enters work orders on behalf of IC, picking
     * the requester department and account (internal-only, so never an IC
     * user).
     */
    public function createOnBehalf(User $user): bool
    {
        return ! $user->isClient() && $user->checkPermissionTo(Permission::WorkOrdersCreateOnBehalf->value);
    }

    /**
     * Determine whether the user can correct the requester (account or
     * contact name, within the same department) of a work order: only the
     * koordinator who entered it on behalf of someone else, while it is
     * editable (Draft and Ditolak). See the open point in FLOW.md §11.
     */
    public function updateRequester(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible(
            $user,
            $workOrder,
            $workOrder->status->isEditable()
                && $workOrder->wasEnteredOnBehalf()
                && $workOrder->created_by === $user->id
                && $this->createOnBehalf($user),
        );
    }

    /**
     * Determine whether the user can edit the work order: the requester side,
     * while its status isEditable() (Draft and Ditolak).
     */
    public function update(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible(
            $user,
            $workOrder,
            $workOrder->status->isEditable() && $workOrder->isOnSide($user, WorkOrderSide::Requester),
        );
    }

    /**
     * Determine whether the user can move the work order to the given status:
     * the side that status names in performedBy() (FLOW.md §5). Whether the
     * current status allows it is checked by the request and, under a lock,
     * by TransitionWorkOrder.
     */
    public function transition(User $user, WorkOrder $workOrder, string $to): Response
    {
        $side = WorkOrderStatus::fromName($to)?->performedBy();

        return $this->ifVisible($user, $workOrder, $side instanceof WorkOrderSide && $workOrder->isOnSide($user, $side));
    }

    /**
     * Determine whether the user acts for either side of the work order, so
     * a status change it cannot make (e.g. from a page loaded before someone
     * else changed the status) is answered with a validation message rather
     * than a 403.
     */
    public function changeStatus(User $user, WorkOrder $workOrder): Response
    {
        return $this->ifVisible(
            $user,
            $workOrder,
            $workOrder->isOnSide($user, WorkOrderSide::Requester) || $workOrder->isOnSide($user, WorkOrderSide::Executor),
        );
    }

    /**
     * Determine whether the user can attach a file to the work order: the
     * side the status names in attachmentSide() (FLOW.md §5), to the
     * documents collection.
     */
    public function addAttachment(User $user, WorkOrder $workOrder, string $collection): Response
    {
        return $this->ifVisible(
            $user,
            $workOrder,
            $collection === WorkOrder::DOCUMENTS && $this->mayChangeAttachments($user, $workOrder),
        );
    }

    /**
     * Determine whether the user can remove an attachment from the work
     * order: same rule as adding. The executor side removes only the files
     * its user uploaded, since the requester's documents are part of the
     * request it accepted.
     */
    public function deleteAttachment(User $user, WorkOrder $workOrder, Media $media): Response
    {
        return $this->ifVisible(
            $user,
            $workOrder,
            $media->collection_name === WorkOrder::DOCUMENTS
                && $this->mayChangeAttachments($user, $workOrder)
                && ($workOrder->status->attachmentSide() !== WorkOrderSide::Executor || $media->uploaded_by === $user->id),
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

    private function mayChangeAttachments(User $user, WorkOrder $workOrder): bool
    {
        $side = $workOrder->status->attachmentSide();

        return $side !== null && $workOrder->isOnSide($user, $side);
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
