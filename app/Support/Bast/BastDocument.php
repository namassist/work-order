<?php

namespace App\Support\Bast;

use App\Support\Html\HtmlFragment;
use Dom\Document;
use Dom\Element;
use Dom\Node;

/**
 * Builds the HTML page a BAST PDF is rendered from: the template's HTML,
 * sanitized again, its placeholders filled (BastTemplateHtml::fill()), its
 * images embedded as data: URIs, inside a page whose styles and markings
 * (draft watermark and banner) belong to the application. The template is
 * never compiled or evaluated, and the page references no file or URL, so
 * the PDF engine has nothing to fetch.
 */
final class BastDocument
{
    /** The image types a template may embed. */
    private const array IMAGE_TYPES = ['image/png', 'image/jpeg'];

    /**
     * @param  array<string, array{name: string, mime: string, data: string}>  $images  the images the template may show, by upload uuid
     */
    public static function html(string $templateHtml, array $images, BastValues $values, BastDocumentKind $kind): string
    {
        $sanitized = BastTemplateHtml::forRender($templateHtml, fn (string $uuid): ?string => $images[$uuid]['name'] ?? null);
        $filled = BastTemplateHtml::fill($sanitized, $values->text, [
            BastPlaceholder::TabelLaporanHarian->value => fn (Document $document): array => self::dailyReportTable($document, $values->dailyReports),
        ]);

        return self::page(self::withEmbeddedImages($filled, $images), $kind);
    }

    /**
     * The daily report table, built by the application (never by the
     * template author): number, date, and note, oldest first.
     *
     * @param  list<array{date: string, note: string}>  $reports
     * @return list<Node>
     */
    private static function dailyReportTable(Document $document, array $reports): array
    {
        if ($reports === []) {
            $paragraph = $document->createElement('p');
            $paragraph->append('Belum ada laporan harian.');

            return [$paragraph];
        }

        $table = $document->createElement('table');
        $table->setAttribute('class', 'daily-reports');
        $table->append($row = $document->createElement('tr'));

        foreach (['No.', 'Tanggal', 'Catatan'] as $heading) {
            $row->append($cell = $document->createElement('th'));
            $cell->append($heading);
        }

        foreach ($reports as $index => $report) {
            $table->append($row = $document->createElement('tr'));

            foreach ([(string) ($index + 1), $report['date'], $report['note']] as $value) {
                $row->append($cell = $document->createElement('td'));

                // The note's line breaks, kept as <br>; the text itself stays text.
                foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $value)) as $line => $text) {
                    if ($line > 0) {
                        $cell->append($document->createElement('br'));
                    }

                    $cell->append($text);
                }
            }
        }

        return [$table];
    }

    /**
     * Replace each image's attachment route with its content as a data: URI
     * (PNG or JPEG only), so the PDF engine never opens a file or URL.
     *
     * @param  array<string, array{name: string, mime: string, data: string}>  $images
     */
    private static function withEmbeddedImages(string $html, array $images): string
    {
        $body = HtmlFragment::parse($html);

        foreach (iterator_to_array($body->querySelectorAll('img')) as $image) {
            /** @var Element $image */
            $uuid = substr((string) $image->getAttribute('src'), strlen('/attachments/'));
            $embedded = $images[$uuid] ?? null;

            $content = $embedded === null ? false : base64_decode($embedded['data'], true);
            $size = $content === false ? false : @getimagesizefromstring($content);
            $maxSide = config()->integer('work_order.bast.images.max_side_px');

            // The size check reads the header only; a larger image would be decoded by the PDF engine.
            if ($embedded === null || ! in_array($embedded['mime'], self::IMAGE_TYPES, true)
                || $size === false || $size[0] > $maxSide || $size[1] > $maxSide) {
                $image->remove();

                continue;
            }

            $image->setAttribute('src', 'data:'.$embedded['mime'].';base64,'.$embedded['data']);
        }

        return $body->innerHTML;
    }

    private static function page(string $body, BastDocumentKind $kind): string
    {
        $markings = '';

        if ($kind->watermark() !== null) {
            $markings .= '<div class="watermark">'.htmlspecialchars($kind->watermark()).'</div>';
        }

        if ($kind->banner() !== null) {
            $markings .= '<div class="banner">'.htmlspecialchars($kind->banner()).'</div>';
        }

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><style>'.self::STYLES.'</style></head>'
            .'<body>'.$markings.'<main>'.$body.'</main></body></html>';
    }

    /**
     * The page's styles: A4, DejaVu (bundled with the PDF engine, full
     * Unicode), and plain document formatting. CSS 2.1, which is what the
     * engine supports.
     */
    private const string STYLES = <<<'CSS'
        @page { size: A4 portrait; margin: 22mm 18mm 20mm 18mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; line-height: 1.45; color: #1a1a1a; }
        h1 { font-size: 15pt; margin: 0 0 8pt; }
        h2 { font-size: 12.5pt; margin: 10pt 0 6pt; }
        h3 { font-size: 11pt; margin: 8pt 0 4pt; }
        p { margin: 0 0 6pt; }
        ul, ol { margin: 0 0 6pt; padding-left: 16pt; }
        li p { margin: 0; }
        hr { border: 0; border-top: 0.8pt solid #333333; margin: 8pt 0; }
        table { width: 100%; border-collapse: collapse; margin: 4pt 0 8pt; }
        th, td { border: 0.6pt solid #555555; padding: 4pt 6pt; vertical-align: top; text-align: left; }
        th { background-color: #eeeeee; }
        td p, th p { margin: 0; }
        /* A template's own tables (details, signatures) stay on one page; the daily report table may split, never inside a row. */
        table { page-break-inside: avoid; }
        table.daily-reports { page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        img { max-width: 100%; }
        main > img { display: block; margin: 0 auto 8pt; }
        table.daily-reports td:first-child { width: 8%; }
        table.daily-reports td:first-child + td { width: 24%; }
        .watermark { position: fixed; top: 40%; left: 0; right: 0; z-index: -1000; text-align: center; font-size: 90pt; font-weight: bold; color: #ececec; transform: rotate(-35deg); }
        .banner { position: fixed; top: -14mm; left: 0; right: 0; text-align: center; font-size: 8.5pt; font-weight: bold; color: #9b1c1c; }
        CSS;
}
