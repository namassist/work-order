<?php

namespace App\Support\Bast;

use App\Models\WorkOrder;
use App\Models\WorkOrderBast;
use App\Models\WorkOrderDailyReport;
use App\Support\DisplayDate;
use Carbon\CarbonInterface;

/**
 * The values a BAST's placeholders (BastPlaceholder) are filled with, taken
 * from the work order and its BAST. Every value is plain text: the template
 * inserts it as text, so it is escaped however it looks. The daily report
 * table (a block placeholder) is built by BastDocument from $dailyReports.
 */
final readonly class BastValues
{
    /**
     * Indonesian month names for dates written out in the document.
     *
     * @var array<int, string>
     */
    private const array MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    public const string EMPTY = '-';

    public const string AWAITING_APPROVAL = '(menunggu persetujuan)';

    /**
     * @param  array<string, string>  $text  inline values by placeholder key
     * @param  list<array{date: string, note: string}>  $dailyReports  oldest first
     */
    public function __construct(public array $text, public array $dailyReports) {}

    /**
     * The values of a work order's BAST. Without a BAST (a template preview)
     * its number and submitter are stand-ins; the Direktur's name and the
     * approval time are filled only for the final PDF.
     */
    public static function for(WorkOrder $workOrder, ?WorkOrderBast $bast, BastDocumentKind $kind, ?string $approverName = null, ?CarbonInterface $approvedAt = null): self
    {
        $workOrder->loadMissing(['requesterDepartment', 'targetDepartment', 'category']);
        $reports = $workOrder->dailyReports()->reorder('report_date')->get(['report_date', 'note']);
        $final = $kind === BastDocumentKind::Final && $approverName !== null && $approvedAt instanceof CarbonInterface;

        $firstReport = $reports->first();
        $lastReport = $reports->last();
        $period = match (true) {
            ! $firstReport instanceof WorkOrderDailyReport || ! $lastReport instanceof WorkOrderDailyReport => self::EMPTY,
            $firstReport->report_date->equalTo($lastReport->report_date) => self::longDate($firstReport->report_date->toDateString()),
            default => self::longDate($firstReport->report_date->toDateString()).' – '.self::longDate($lastReport->report_date->toDateString()),
        };

        $text = [
            BastPlaceholder::NomorBast->value => $bast->number ?? '(nomor BAST)',
            BastPlaceholder::TanggalBast->value => self::longDate(DisplayDate::local($bast->submitted_at ?? now())->toDateString()),
            BastPlaceholder::NamaPengajuBast->value => $bast?->submitter->name ?? '(pengaju BAST)',
            BastPlaceholder::NomorWo->value => $workOrder->displayNumber(),
            BastPlaceholder::Judul->value => $workOrder->title,
            BastPlaceholder::Deskripsi->value => filled($workOrder->description) ? (string) $workOrder->description : self::EMPTY,
            BastPlaceholder::Kategori->value => $workOrder->category->name,
            BastPlaceholder::DepartemenPemohon->value => $workOrder->requesterDepartment->name,
            BastPlaceholder::KontakPemohon->value => $workOrder->requester_name,
            BastPlaceholder::DepartemenTujuan->value => $workOrder->targetDepartment->name ?? self::EMPTY,
            BastPlaceholder::PicWo->value => filled($workOrder->pic_name) ? (string) $workOrder->pic_name : self::EMPTY,
            BastPlaceholder::TanggalTarget->value => $workOrder->target_date ? self::longDate($workOrder->target_date->toDateString()) : self::EMPTY,
            BastPlaceholder::PeriodePelaksanaan->value => $period,
            BastPlaceholder::JumlahLaporanHarian->value => (string) $reports->count(),
            BastPlaceholder::NamaDirektur->value => $final ? $approverName : self::AWAITING_APPROVAL,
            BastPlaceholder::TanggalPersetujuan->value => $final ? self::longDateTime($approvedAt) : self::AWAITING_APPROVAL,
        ];

        return new self($text, array_values($reports->map(fn (WorkOrderDailyReport $report): array => [
            'date' => self::longDate($report->report_date->toDateString()),
            'note' => $report->note,
        ])->all()));
    }

    /**
     * A calendar date (Y-m-d) written out, e.g. "30 September 2026". Never
     * timezone-converted.
     */
    public static function longDate(string $date): string
    {
        [$year, $month, $day] = array_map(intval(...), explode('-', $date));

        return $day.' '.self::MONTHS[$month].' '.$year;
    }

    /**
     * A moment in the display timezone, e.g. "30 September 2026 10:15 WITA".
     */
    public static function longDateTime(CarbonInterface $moment): string
    {
        $local = DisplayDate::local($moment);

        return self::longDate($local->toDateString()).' '.$local->format('H:i T');
    }
}
