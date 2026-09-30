<?php

namespace App\Http\Controllers\WorkOrders;

use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrders\TransitionWorkOrderRequest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Bast\BastGenerationFailed;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

class WorkOrderTransitionController extends Controller
{
    /**
     * Move the work order to another status.
     */
    public function store(TransitionWorkOrderRequest $request, WorkOrder $workOrder, TransitionWorkOrder $transition): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $workOrder = $transition->handle($workOrder, $request->string('status')->toString(), $user, $request->input('note'));
        } catch (CouldNotPerformTransition) {
            // Someone else changed the status between loading the page and now.
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Status work order sudah berubah. Muat ulang halaman lalu coba lagi.')]);

            return back();
        } catch (BastGenerationFailed $exception) {
            // The transition rolled back: no status change, number, or file was kept.
            report($exception);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('BAST gagal dibuat, jadi status tidak berubah. Coba lagi atau hubungi admin.')]);

            return back();
        }

        // The label as shown in badges: "Approval BAST" and "BAST Disetujui" do not lowercase well.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status work order :number sekarang :status.', [
            'number' => $workOrder->reference(),
            'status' => $workOrder->status->label(),
        ])]);

        return back();
    }
}
