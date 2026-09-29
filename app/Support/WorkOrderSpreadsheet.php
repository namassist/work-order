<?php

namespace App\Support;

use App\Models\Department;
use App\Models\WorkOrder;
use Carbon\CarbonInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes work orders to an .xlsx file, one row each, streaming as it goes.
 *
 * Every text cell is built as a StringCell: openspout's Cell::fromValue()
 * would turn user text starting with "=" into a formula. Timestamps are
 * written as Excel dates in the display timezone; the target date and the
 * invoice's dates are calendar dates and are never converted. The invoice
 * amount is a number cell in a Rupiah format, so it can be summed.
 */
class WorkOrderSpreadsheet
{
    /**
     * Column headers and their widths, in characters.
     *
     * @var array<string, float>
     */
    private const array COLUMNS = [
        'Nomor' => 22,
        'Judul' => 40,
        'Deskripsi' => 60,
        'Departemen pemohon' => 28,
        'Departemen tujuan' => 28,
        'Kategori' => 22,
        'Kontak pemohon' => 24,
        'PIC Work Order' => 24,
        'Diinput oleh' => 24,
        'Status' => 14,
        'Urgensi' => 12,
        'Target' => 14,
        'Dibuat' => 18,
        'Diajukan' => 18,
        'Status pembayaran' => 16,
        'No. invoice' => 22,
        'Tanggal invoice' => 14,
        'Jumlah' => 18,
        'Jatuh tempo' => 14,
        'Tanggal bayar' => 14,
    ];

    private const string DATE_FORMAT = 'd mmm yyyy';

    private const string DATE_TIME_FORMAT = 'd mmm yyyy hh:mm';

    /**
     * Rupiah with thousands separators and cents, e.g. "Rp 1,500,000.50";
     * Excel shows the separators of the reader's locale.
     */
    private const string RUPIAH_FORMAT = '"Rp "#,##0.00';

    /**
     * Write the work orders to $path. Load requesterDepartment,
     * targetDepartment, category, enteredBy, and invoice first,
     * and select a `submitted_at` datetime (null if never submitted).
     *
     * @param  iterable<WorkOrder>  $workOrders
     */
    public function write(iterable $workOrders, string $path): void
    {
        $options = new Options;

        foreach (array_values(self::COLUMNS) as $index => $width) {
            $options->setColumnWidth($width, $index + 1);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Work Order');
        $sheet->setSheetView((new SheetView)->withFreezeRow(2));

        $writer->addRow($this->headerRow());

        $dateStyle = new Style(format: self::DATE_FORMAT);
        $dateTimeStyle = new Style(format: self::DATE_TIME_FORMAT);
        $rupiahStyle = new Style(format: self::RUPIAH_FORMAT);

        foreach ($workOrders as $workOrder) {
            $invoice = $workOrder->invoice;

            $writer->addRow(new Row([
                new StringCell($workOrder->displayNumber()),
                new StringCell($workOrder->title),
                $this->text($workOrder->description),
                new StringCell($this->department($workOrder->requesterDepartment)),
                $this->text($workOrder->targetDepartment === null ? null : $this->department($workOrder->targetDepartment)),
                new StringCell($workOrder->category->code.' - '.$workOrder->category->name),
                new StringCell($workOrder->requester_name),
                $this->text($workOrder->pic_name),
                new StringCell($workOrder->enteredBy->name),
                new StringCell($workOrder->status->label()),
                new StringCell($workOrder->urgency->label()),
                $this->date($workOrder->target_date, $dateStyle),
                $this->date($workOrder->created_at === null ? null : DisplayDate::local($workOrder->created_at), $dateTimeStyle),
                $this->date($this->submittedAt($workOrder), $dateTimeStyle),
                $this->text($workOrder->paymentStatus()?->label()),
                $this->text($invoice?->number),
                $this->date($invoice?->invoice_date, $dateStyle),
                $invoice?->amount === null ? new EmptyCell(null) : new NumericCell((float) $invoice->amount, $rupiahStyle),
                $this->date($invoice?->due_date, $dateStyle),
                $this->date($invoice?->paid_on, $dateStyle),
            ]));
        }

        $writer->close();
    }

    private function headerRow(): Row
    {
        $style = new Style(fontBold: true, fontColor: Color::WHITE, backgroundColor: '1C322D');

        return new Row(array_map(
            fn (string $header): Cell => new StringCell($header, $style),
            array_keys(self::COLUMNS),
        ));
    }

    private function department(Department $department): string
    {
        return $department->code.' - '.$department->name;
    }

    private function text(?string $value): Cell
    {
        return $value === null || $value === '' ? new EmptyCell(null) : new StringCell($value);
    }

    private function date(?CarbonInterface $moment, Style $style): Cell
    {
        return $moment instanceof CarbonInterface ? new DateTimeCell($moment, $style) : new EmptyCell(null);
    }

    private function submittedAt(WorkOrder $workOrder): ?CarbonInterface
    {
        $submittedAt = $workOrder->getAttribute('submitted_at');

        return $submittedAt instanceof CarbonInterface ? DisplayDate::local($submittedAt) : null;
    }
}
