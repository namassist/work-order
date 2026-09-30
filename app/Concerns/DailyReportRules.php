<?php

namespace App\Concerns;

use App\Models\WorkOrderDailyReport;
use App\Rules\DailyReportLink;
use Illuminate\Http\UploadedFile;

/**
 * Validation of a daily report's note, links, and files (FLOW.md §7),
 * shared by posting and editing. The date window, the "at least one file
 * or link" rule, and the file count with existing files are checked by the
 * actions under the work order's lock.
 */
trait DailyReportRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function dailyReportContentRules(): array
    {
        $files = new WorkOrderDailyReport()->attachmentCollections()[WorkOrderDailyReport::FILES];

        return [
            'note' => ['required', 'string', 'max:'.config()->integer('work_order.daily_reports.note_max_length')],
            'links' => ['nullable', 'array', 'max:'.config()->integer('work_order.daily_reports.links.max_links')],
            'links.*' => ['bail', 'required', 'string', 'distinct', new DailyReportLink],
            'files' => ['nullable', 'array', 'max:'.$files->maxFiles],
            'files.*' => $files->fileRules(),
        ];
    }

    /**
     * The validated note and links; an absent list means no links.
     *
     * @return array{note: string, links: list<string>}
     */
    public function reportContent(): array
    {
        return [
            'note' => (string) $this->validated('note'),
            'links' => array_values(array_map(strval(...), (array) $this->validated('links', []))),
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploads(): array
    {
        /** @var list<UploadedFile> */
        return array_values((array) $this->file('files', []));
    }
}
