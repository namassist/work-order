<?php

namespace App\Http\Controllers\WorkOrders;

use App\Actions\WorkOrders\BillWorkOrder;
use App\Actions\WorkOrders\CorrectInvoice;
use App\Actions\WorkOrders\InvoiceNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrders\StoreWorkOrderInvoiceRequest;
use App\Http\Requests\WorkOrders\UpdateWorkOrderInvoiceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class WorkOrderInvoiceController extends Controller
{
    /**
     * Bill the closed work order: its payment becomes Ditagih (FLOW.md §10).
     */
    public function store(StoreWorkOrderInvoiceRequest $request, WorkOrder $workOrder, BillWorkOrder $bill): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $workOrder = $bill->handle($workOrder, $user, $request->invoiceAttributes(), $request->uploads());
        } catch (InvoiceNotAllowed $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice work order :number diterbitkan.', ['number' => $workOrder->reference()])]);

        return back();
    }

    /**
     * Correct the invoice while it waits for payment.
     */
    public function update(UpdateWorkOrderInvoiceRequest $request, WorkOrder $workOrder, CorrectInvoice $correct): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $correct->handle($workOrder, $user, $request->invoiceAttributes(), $request->uploads(), $request->removedFiles());
        } catch (InvoiceNotAllowed $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice diperbarui.')]);

        return back();
    }
}
