<?php

namespace App\Actions\WorkOrders;

use App\Actions\Attachments\AddAttachment;
use App\Concerns\LogsAuditChanges;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDailyReport;
use App\States\WorkOrder\Pelaksanaan;
use App\Support\DailyReports\ReportCalendar;
use App\Support\DisplayDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a daily report on a work order in Pelaksanaan (FLOW.md §7), in one
 * transaction with the work order locked: checks the date (not in the
 * future, within the back-dating window, not before the work order first
 * entered Pelaksanaan, not already reported), stores the report and its
 * files, and logs it on the work order with its file names. A refused
 * report stores nothing.
 */
class AddDailyReport
{
    use LogsAuditChanges;

    public function __construct(private readonly AddAttachment $addAttachment) {}

    /**
     * @param  array{report_date: string, note: string, links: list<string>}  $report
     * @param  list<UploadedFile>  $files
     *
     * @throws DailyReportNotAllowed when the work order is not in Pelaksanaan
     * @throws ValidationException when the date is refused or the report has no file and no link
     */
    public function handle(WorkOrder $workOrder, User $user, array $report, array $files): WorkOrderDailyReport
    {
        try {
            return DB::transaction(function () use ($workOrder, $user, $report, $files): WorkOrderDailyReport {
                $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

                if (! $locked->status->equals(Pelaksanaan::class)) {
                    throw DailyReportNotAllowed::notInExecution();
                }

                $this->ensureDateAllowed($locked, $report['report_date']);

                if ($files === [] && $report['links'] === []) {
                    throw ValidationException::withMessages(['links' => __('Laporan harus memiliki minimal satu berkas atau tautan.')]);
                }

                $record = new WorkOrderDailyReport($report);
                $record->workOrder()->associate($locked);
                $record->reporter()->associate($user);
                $record->save();

                $collection = $record->attachmentCollections()[WorkOrderDailyReport::FILES];

                foreach ($files as $index => $file) {
                    $this->addAttachment->handle($record, $collection, $file, $user, "files.{$index}");
                }

                $this->logAuditChange($locked, AuditEvent::DailyReportAdded, [], $record->auditValues($record->files()->get()));

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            // Another report took the date between the check and saving.
            throw $this->dateTaken();
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureDateAllowed(WorkOrder $workOrder, string $date): void
    {
        $today = ReportCalendar::today();
        $earliest = ReportCalendar::earliestReportDate();
        $started = $workOrder->executionStartedOn();

        $message = match (true) {
            $date > $today => __('Tanggal laporan tidak boleh setelah hari ini.'),
            $date < $earliest => __('Laporan paling awal boleh bertanggal :date.', ['date' => DisplayDate::calendarDate($earliest)]),
            $started !== null && $date < $started => __('Tanggal laporan tidak boleh sebelum work order mulai dilaksanakan (:date).', ['date' => DisplayDate::calendarDate($started)]),
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['report_date' => $message]);
        }

        if ($workOrder->dailyReports()->where('report_date', $date)->exists()) {
            throw $this->dateTaken();
        }
    }

    private function dateTaken(): ValidationException
    {
        return ValidationException::withMessages(['report_date' => __('Laporan untuk tanggal ini sudah ada. Ubah laporan tersebut.')]);
    }
}
