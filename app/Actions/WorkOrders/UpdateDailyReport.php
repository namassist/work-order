<?php

namespace App\Actions\WorkOrders;

use App\Actions\Attachments\AddAttachment;
use App\Actions\Attachments\RemoveAttachment;
use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDailyReport;
use App\States\WorkOrder\Pelaksanaan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a daily report (FLOW.md §7): its note, links, and files (added and
 * removed; its date stays), by any holder of work-orders.report, who
 * becomes its editor. Only while the work order is in Pelaksanaan and
 * within the edit window, in one transaction with the work order and the
 * report locked. Logged on the work order with the changed fields before
 * and after; an edit that changes nothing records nothing.
 */
class UpdateDailyReport
{
    use LogsAuditChanges;

    public function __construct(
        private readonly AddAttachment $addAttachment,
        private readonly RemoveAttachment $removeAttachment,
    ) {}

    /**
     * @param  array{note: string, links: list<string>}  $changes
     * @param  list<UploadedFile>  $files  files to add
     * @param  list<string>  $removeMediaUuids  report files to remove
     *
     * @throws DailyReportNotAllowed when the work order left Pelaksanaan or the edit window has passed
     * @throws ValidationException when a file is refused or the report would have no file and no link
     */
    public function handle(WorkOrder $workOrder, WorkOrderDailyReport $report, User $user, array $changes, array $files = [], array $removeMediaUuids = []): WorkOrderDailyReport
    {
        return DB::transaction(function () use ($workOrder, $report, $user, $changes, $files, $removeMediaUuids): WorkOrderDailyReport {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $record = $locked->dailyReports()->lockForUpdate()->findOrFail($report->id);

            if (! $locked->status->equals(Pelaksanaan::class)) {
                throw DailyReportNotAllowed::notInExecution();
            }

            if (! $record->isWithinEditWindow()) {
                throw DailyReportNotAllowed::editWindowClosed();
            }

            $current = $record->files()->get();
            $before = $record->auditValues($current);
            $removed = $current->whereIn('uuid', $removeMediaUuids);

            $record->fill($changes);

            if (! $record->isDirty() && $removed->isEmpty() && $files === []) {
                return $record;
            }

            if ($current->count() - $removed->count() + count($files) === 0 && $record->links === []) {
                throw ValidationException::withMessages(['links' => __('Laporan harus memiliki minimal satu berkas atau tautan.')]);
            }

            $record->editor()->associate($user);
            // touch() also saves the changed fields and bumps the work order's last activity.
            $record->touch();

            // The files being replaced still count until they are deleted, last.
            $collection = $record->attachmentCollections()[WorkOrderDailyReport::FILES]->withRoomFor($removed->count());

            foreach ($files as $index => $file) {
                $this->addAttachment->handle($record, $collection, $file, $user, "files.{$index}");
            }

            $remaining = $record->files()->whereNotIn('uuid', $removed->pluck('uuid'))->get();

            $this->logAuditChange($locked, AuditEvent::DailyReportEdited, $before, $record->auditValues($remaining));

            // Last: deleting a file cannot be rolled back.
            foreach ($removed as $media) {
                /** @var Media $media */
                $this->removeAttachment->handle($record, $media);
            }

            return $record;
        });
    }
}
