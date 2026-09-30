<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrderBast;
use Illuminate\Auth\Access\Response;

/**
 * The files of a BAST (FLOW.md §6, §8). Its PDFs are generated only by the
 * transitions (GenerateBast, ApproveBast); nobody adds, replaces, or
 * removes one, and the final PDF is also protected by a database trigger.
 */
class WorkOrderBastPolicy
{
    public function __construct(private readonly WorkOrderPolicy $workOrders) {}

    /**
     * Determine whether the user can open one of the BAST's PDFs: whoever
     * may view its work order, 404 otherwise. BASTs are internal (Unggul)
     * data, like daily reports, so never a client company user, even of
     * the requesting department (the v1 safeguard).
     */
    public function viewAttachment(User $user, WorkOrderBast $bast, Media $media): Response
    {
        $workOrder = $bast->workOrder;

        if ($user->isClient() || $workOrder->trashed()) {
            return Response::denyAsNotFound();
        }

        return $this->workOrders->view($user, $workOrder);
    }

    /**
     * BAST PDFs are generated, never uploaded.
     */
    public function addAttachment(User $user, WorkOrderBast $bast, string $collection): Response
    {
        return Response::denyAsNotFound();
    }

    /**
     * BAST PDFs are never removed.
     */
    public function deleteAttachment(User $user, WorkOrderBast $bast, Media $media): Response
    {
        return Response::deny();
    }
}
