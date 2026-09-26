<?php

namespace App\Support;

use App\Models\WorkOrder;
use Carbon\CarbonInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
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
 * written as Excel dates in the display timezone; the target date is a
 * calendar date and is never converted.
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
        'Departemen' => 28,
        'Kategori' => 22,
        'Pemohon' => 24,
        'Status' => 14,
        'Target' => 14,
        'Dibuat' => 18,
        'Diajukan' => 18,
    ];

    private const string DATE_FORMAT = 'd mmm yyyy';

    private const string DATE_TIME_FORMAT = 'd mmm yyyy hh:mm';

    /**
     * Write the work orders to $path. Load department, category, and
     * requester first, and select a `submitted_at` datetime (null if never
     * submitted).
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

        foreach ($workOrders as $workOrder) {
            $writer->addRow(new Row([
                new StringCell($workOrder->displayNumber()),
                new StringCell($workOrder->title),
                $this->text($workOrder->description),
                new StringCell($workOrder->department->code.' - '.$workOrder->department->name),
                new StringCell($workOrder->category->code.' - '.$workOrder->category->name),
                new StringCell($workOrder->requester->name),
                new StringCell($workOrder->status->label()),
                $this->date($workOrder->target_date, $dateStyle),
                $this->date($workOrder->created_at === null ? null : DisplayDate::local($workOrder->created_at), $dateTimeStyle),
                $this->date($this->submittedAt($workOrder), $dateTimeStyle),
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
