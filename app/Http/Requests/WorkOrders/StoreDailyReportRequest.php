<?php

namespace App\Http\Requests\WorkOrders;

use App\Concerns\DailyReportRules;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Posting a daily report (FLOW.md §7): its date, note, links, and files.
 * Whether the work order is still in Pelaksanaan and the date is allowed is
 * checked by AddDailyReport under a lock.
 */
class StoreDailyReportRequest extends FormRequest
{
    use DailyReportRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        // The policy's response keeps its 404 for work orders the user cannot see.
        return Gate::inspect('report', $this->workOrder());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'report_date' => ['required', 'date_format:Y-m-d'],
            ...$this->dailyReportContentRules(),
        ];
    }

    /**
     * @return array{report_date: string, note: string, links: list<string>}
     */
    public function reportAttributes(): array
    {
        return ['report_date' => (string) $this->validated('report_date'), ...$this->reportContent()];
    }

    /**
     * The work order being reported on.
     */
    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }
}
