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

class WorkOrderPaymentController extends Controller
{
    /**
     * Confirm that the invoice was paid: the payment becomes Lunas (FLOW.md §10).
     */
    public function store(ConfirmWorkOrderPaymentRequest $request, WorkOrder $workOrder, ConfirmWorkOrderPayment $confirm): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $workOrder = $confirm->handle($workOrder, $user, $request->string('paid_on')->toString(), $request->uploads());
        } catch (InvoiceNotAllowed $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pembayaran work order :number dikonfirmasi. Status pembayaran: Lunas.', ['number' => $workOrder->reference()])]);

        return back();
    }
}
