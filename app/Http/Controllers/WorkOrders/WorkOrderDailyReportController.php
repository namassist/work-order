<?php

namespace App\Http\Controllers\WorkOrders;

use App\Actions\WorkOrders\AddDailyReport;
use App\Actions\WorkOrders\DailyReportNotAllowed;
use App\Actions\WorkOrders\UpdateDailyReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrders\StoreDailyReportRequest;
use App\Http\Requests\WorkOrders\UpdateDailyReportRequest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDailyReport;
use App\Support\DisplayDate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class WorkOrderDailyReportController extends Controller
{
    /**
     * Post a daily report on a work order in Pelaksanaan (FLOW.md §7).
     */
    public function store(StoreDailyReportRequest $request, WorkOrder $workOrder, AddDailyReport $add): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $report = $add->handle($workOrder, $user, $request->reportAttributes(), $request->uploads());
        } catch (DailyReportNotAllowed $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Laporan harian :date disimpan.', ['date' => DisplayDate::calendarDate($report->report_date->toDateString())])]);

        return back();
    }

    /**
     * Edit a daily report while its work order is in Pelaksanaan.
     */
    public function update(UpdateDailyReportRequest $request, WorkOrder $workOrder, WorkOrderDailyReport $dailyReport, UpdateDailyReport $update): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $update->handle($workOrder, $dailyReport, $user, $request->reportContent(), $request->uploads(), $request->removedFiles());
        } catch (DailyReportNotAllowed $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Laporan harian :date diperbarui.', ['date' => DisplayDate::calendarDate($dailyReport->report_date->toDateString())])]);

        return back();
    }
}
