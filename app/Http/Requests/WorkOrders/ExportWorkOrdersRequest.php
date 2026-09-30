<?php

namespace App\Http\Requests\WorkOrders;

use App\Enums\PaymentStatus;
use App\Enums\WorkOrderUrgency;
use App\Models\Department;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\States\WorkOrder\WorkOrderStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * The work order list's filters, for downloading the list as a spreadsheet.
 */
class ExportWorkOrdersRequest extends ListWorkOrdersRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return parent::authorize() && $this->listingUser()->can('export', WorkOrder::class);
    }

    /**
     * The filters in use, for the activity log, e.g. "Cari: pompa; Status: Draft".
     * The sort is left out: it changes the order, not which rows are exported.
     */
    public function filterDescription(): string
    {
        $filters = $this->filters();

        $parts = array_filter([
            'Cari' => $filters['search'],
            'Status' => $filters['status'] === '' ? '' : WorkOrderStatus::filterLabelFor($filters['status']),
            'Urgensi' => WorkOrderUrgency::tryFrom($filters['urgency'])?->label() ?? '',
            'Pembayaran' => PaymentStatus::tryFrom($filters['payment'])?->label() ?? '',
            'Dept. pemohon' => $filters['department'] === '' ? '' : $this->codeOf(Department::withTrashed(), $filters['department']),
            'Dept. tujuan' => $filters['target'] === '' ? '' : $this->codeOf(Department::withTrashed(), $filters['target']),
            'Kategori' => $filters['category'] === '' ? '' : $this->codeOf(WorkOrderCategory::withTrashed(), $filters['category']),
            'Terlambat' => $filters['overdue'] ? 'Ya' : '',
            'Belum lapor' => $filters['missing_report'] ? 'Ya' : '',
            'Dari' => $filters['from'],
            'Sampai' => $filters['to'],
            'Terhapus' => $filters['trashed'] ? 'Ya' : '',
        ], fn (string $value): bool => $value !== '');

        if ($parts === []) {
            return 'Semua';
        }

        return implode('; ', array_map(
            fn (string $label, string $value): string => $label.': '.$value,
            array_keys($parts),
            $parts,
        ));
    }

    /**
     * The code of the record with this id, or the id itself when none has it.
     *
     * @param  Builder<Department>|Builder<WorkOrderCategory>  $query
     */
    private function codeOf(Builder $query, string $id): string
    {
        $code = $query->whereKey((int) $id)->value('code');

        return is_string($code) ? $code : $id;
    }
}
