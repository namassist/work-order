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
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

class WorkOrderInvoiceController extends Controller
{
    /**
     * Invoice the work order and move it to Penagihan (FLOW.md §8).
     */
    public function store(StoreWorkOrderInvoiceRequest $request, WorkOrder $workOrder, BillWorkOrder $bill): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $workOrder = $bill->handle($workOrder, $user, $request->invoiceAttributes(), $request->uploads('invoice_files'), $request->uploads('bast_files'));
        } catch (CouldNotPerformTransition) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Status work order sudah berubah. Muat ulang halaman lalu coba lagi.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice work order :number diterbitkan.', ['number' => $workOrder->reference()])]);

        return back();
    }

    /**
     * Correct the invoice while the work order waits for payment.
     */
    public function update(UpdateWorkOrderInvoiceRequest $request, WorkOrder $workOrder, CorrectInvoice $correct): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $correct->handle($workOrder, $user, $request->invoiceAttributes(), $request->uploads('invoice_files'), $request->uploads('bast_files'), $request->removedFiles());
        } catch (InvoiceNotAllowed $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice diperbarui.')]);

        return back();
    }
}
