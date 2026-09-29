<?php

namespace App\Http\Requests\WorkOrders;

use App\Models\WorkOrder;
use App\Support\DisplayDate;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Confirming payment (payment track Ditagih → Lunas, FLOW.md §10): the
 * payment date, not before the invoice date and not in the future (WITA),
 * and optional proof of payment. Whether it is still billed is checked by
 * ConfirmWorkOrderPayment under a lock.
 */
class ConfirmWorkOrderPaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        // The policy's response keeps its 404 for work orders the user cannot see.
        return Gate::inspect('confirmPayment', $this->workOrder());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $workOrder = $this->workOrder();
        $proof = $workOrder->attachmentCollections()[WorkOrder::PAYMENT_PROOF];
        $invoiceDate = $workOrder->invoice?->invoice_date->toDateString();

        return [
            'paid_on' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:'.DisplayDate::today(),
                // Re-checked by ConfirmWorkOrderPayment, in case the invoice is corrected meanwhile.
                ...($invoiceDate !== null ? ['after_or_equal:'.$invoiceDate] : []),
            ],
            'proof_files' => ['nullable', 'array', 'max:'.$proof->maxFiles],
            'proof_files.*' => $proof->fileRules(),
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploads(): array
    {
        /** @var list<UploadedFile> */
        return array_values((array) $this->file('proof_files', []));
    }

    /**
     * The work order whose payment is confirmed.
     */
    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }
}
