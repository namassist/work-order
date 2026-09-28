<?php

namespace App\Http\Requests\WorkOrders;

use App\Concerns\AuthorizesFormTransition;
use App\Concerns\WorkOrderInvoiceRules;
use App\Models\WorkOrder;
use App\States\WorkOrder\Penagihan;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Invoicing a work order (Dikerjakan → Penagihan): the invoice data, at
 * least one invoice file, and optional BAST files.
 */
class StoreWorkOrderInvoiceRequest extends FormRequest
{
    use AuthorizesFormTransition, WorkOrderInvoiceRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        return $this->authorizeTransitionTo(Penagihan::$name);
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return $this->transitionableCheck(Penagihan::$name);
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
            ...$this->invoiceFileRules($this->workOrder(), WorkOrder::BAST, 'bast_files', required: false),
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploads(string $input): array
    {
        /** @var list<UploadedFile> */
        return array_values((array) $this->file($input, []));
    }

    /**
     * The work order being invoiced.
     */
    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }
}
