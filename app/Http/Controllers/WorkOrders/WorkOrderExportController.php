<?php

namespace App\Http\Controllers\WorkOrders;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrders\ExportWorkOrdersRequest;
use App\Models\User;
use App\Models\WorkOrderStatusHistory;
use App\States\WorkOrder\Diajukan;
use App\Support\DisplayDate;
use App\Support\WorkOrderSpreadsheet;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkOrderExportController extends Controller
{
    /**
     * Download the work order list, with the same filters and visibility,
     * as an Excel file. Refused above the configured row cap.
     */
    public function __invoke(ExportWorkOrdersRequest $request, WorkOrderSpreadsheet $spreadsheet): StreamedResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rowCount = $request->workOrders()->reorder()->count();
        $maxRows = config()->integer('work_order.export.max_rows');

        if ($rowCount > $maxRows) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Hasil filter berisi :count work order, melebihi batas ekspor :max. Persempit filter lalu coba lagi.', [
                'count' => $rowCount,
                'max' => $maxRows,
            ])]);

            return back();
        }

        activity()
            ->causedBy($user)
            ->event(AuditEvent::Exported->value)
            ->withProperties([
                'filter' => $request->filterDescription(),
                'jumlah_baris' => (string) $rowCount,
                'ip' => (string) $request->ip(),
            ])
            ->log(AuditEvent::Exported->value);

        $workOrders = $request->workOrders()
            ->with(['department', 'category', 'requester'])
            ->addSelect(['submitted_at' => WorkOrderStatusHistory::query()
                ->select('created_at')
                ->whereColumn('work_order_id', 'work_orders.id')
                ->where('to_status', Diajukan::$name)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(1)])
            ->withCasts(['submitted_at' => 'datetime']);

        return response()->streamDownload(
            fn () => $spreadsheet->write($workOrders->lazy(500), 'php://output'),
            'WOrder-WorkOrders-'.DisplayDate::local(now())->format('Ymd-Hi').'.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
