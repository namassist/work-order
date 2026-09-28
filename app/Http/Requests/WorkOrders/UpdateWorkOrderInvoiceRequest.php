<?php

namespace App\Http\Requests\WorkOrders;

use App\Concerns\WorkOrderInvoiceRules;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Correcting the invoice while the work order waits for payment: its data,
 * files to add, and invoice or BAST files to remove.
 */
class UpdateWorkOrderInvoiceRequest extends FormRequest
{
    use WorkOrderInvoiceRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        // The policy's response keeps its 404 for work orders the user cannot see.
        return Gate::inspect('correctInvoice', $this->workOrder());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->invoiceFieldRules($this->workOrder()->invoice),
            ...$this->invoiceFileRules($this->workOrder(), WorkOrder::INVOICE, 'invoice_files', required: false),
            ...$this->invoiceFileRules($this->workOrder(), WorkOrder::BAST, 'bast_files', required: false),
            'remove_files' => ['nullable', 'array'],
            'remove_files.*' => ['uuid'],
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
     * The uuids of the invoice or BAST files to remove.
     *
     * @return list<string>
     */
    public function removedFiles(): array
    {
        return array_values(array_map(strval(...), (array) $this->validated('remove_files', [])));
    }

    /**
     * The work order whose invoice is corrected.
     */
    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }
}
