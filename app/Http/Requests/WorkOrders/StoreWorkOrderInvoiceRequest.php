<?php

namespace App\Http\Requests\WorkOrders;

use App\Concerns\WorkOrderInvoiceRules;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Billing a closed work order (payment track Belum ditagih → Ditagih,
 * FLOW.md §10): the invoice data and at least one invoice file. Whether it
 * may still be billed is checked by BillWorkOrder under a lock.
 */
class StoreWorkOrderInvoiceRequest extends FormRequest
{
    use WorkOrderInvoiceRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        // The policy's response keeps its 404 for work orders the user cannot see.
        return Gate::inspect('bill', $this->workOrder());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->invoiceFieldRules(),
            ...$this->invoiceFileRules($this->workOrder(), WorkOrder::INVOICE, 'invoice_files', required: true),
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploads(): array
    {
        /** @var list<UploadedFile> */
        return array_values((array) $this->file('invoice_files', []));
    }

    /**
     * The work order being billed.
     */
    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }
}
