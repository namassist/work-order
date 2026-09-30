<?php

namespace App\Http\Requests\WorkOrders;

use App\Concerns\DailyReportRules;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Editing a daily report (FLOW.md §7): its note, links, files to add, and
 * files to remove. Its date never changes. The status and edit window are
 * checked by UpdateDailyReport under a lock.
 */
class UpdateDailyReportRequest extends FormRequest
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
            ...$this->dailyReportContentRules(),
            'remove_files' => ['nullable', 'array', 'max:'.config()->integer('work_order.daily_reports.files.max_files')],
            'remove_files.*' => ['uuid', 'distinct'],
        ];
    }

    /**
     * The uuids of the report's files to remove.
     *
     * @return list<string>
     */
    public function removedFiles(): array
    {
        return array_values(array_map(strval(...), (array) $this->validated('remove_files', [])));
    }

    /**
     * The work order the report belongs to.
     */
    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }
}
