<?php

namespace App\Http\Controllers\WorkOrders;

use App\Actions\WorkOrders\ConfirmWorkOrderPayment;
use App\Actions\WorkOrders\InvoiceNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrders\ConfirmWorkOrderPaymentRequest;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

class WorkOrderPaymentController extends Controller
{
    /**
     * Confirm that the invoice was paid and close the work order (Selesai).
     */
    public function store(ConfirmWorkOrderPaymentRequest $request, WorkOrder $workOrder, ConfirmWorkOrderPayment $confirm): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $workOrder = $confirm->handle($workOrder, $user, $request->string('paid_on')->toString(), $request->uploads());
        } catch (CouldNotPerformTransition) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Status work order sudah berubah. Muat ulang halaman lalu coba lagi.')]);

            return back();
        } catch (InvoiceNotAllowed $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pembayaran work order :number dikonfirmasi. Work order selesai.', ['number' => $workOrder->displayNumber()])]);

        return back();
    }
}
