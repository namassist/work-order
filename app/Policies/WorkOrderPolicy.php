<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
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

    private function ifVisible(User $user, WorkOrder $workOrder, bool $allowed): Response
    {
        if (! $workOrder->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        return $allowed ? Response::allow() : Response::deny();
    }
}
