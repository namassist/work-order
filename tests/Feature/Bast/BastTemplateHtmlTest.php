<?php

use App\Support\Bast\BastPlaceholder;
use App\Support\Bast\BastTemplateHtml;
use App\Support\Bast\SanitizedBastTemplate;
use Dom\Document;
use Dom\HTMLDocument;

const TEMPLATE_IMAGE = '0192f3a4-5b6c-7d8e-9f01-23456789abcd';
const OTHER_IMAGE = '0192f3a4-5b6c-7d8e-9f01-ffffffffffff';

/**
 * Sanitizes as the template, which may show only TEMPLATE_IMAGE (kop.png).
 */
function sanitizeTemplate(string $html): SanitizedBastTemplate
{
    return BastTemplateHtml::sanitize($html, fn (string $uuid): ?string => $uuid === TEMPLATE_IMAGE ? 'kop.png' : null);
}

/**
 * Asserts that the HTML holds only the editor's elements and attributes.
 */
function expectOnlyTemplateMarkup(string $html): void
{
    $document = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html.'</body>', LIBXML_NOERROR);
    $allowed = [
        'P' => ['style'], 'H1' => ['style'], 'H2' => ['style'], 'H3' => ['style'], 'BR' => [],
        'STRONG' => [], 'EM' => [], 'U' => [], 'S' => [], 'UL' => [], 'OL' => [], 'LI' => [], 'HR' => [],
        'TABLE' => [], 'TBODY' => [], 'TR' => [], 'TH' => ['colspan', 'rowspan'], 'TD' => ['colspan', 'rowspan'],
        'IMG' => ['src', 'alt'],
    ];

    foreach ($document->body->querySelectorAll('*') as $element) {
        expect(array_key_exists($element->tagName, $allowed))->toBeTrue("<{$element->tagName}> is not allowed");

        foreach ($element->attributes as $attribute) {
            expect($allowed[$element->tagName])->toContain($attribute->name);
        }

        if ($element->hasAttribute('style')) {
            expect($element->getAttribute('style'))->toMatch('/\Atext-align: (left|center|right|justify);\z/');
        }

        if ($element->tagName === 'IMG') {
            expect($element->getAttribute('src'))->toBe('/attachments/'.TEMPLATE_IMAGE);
        }
    }
}

describe('editor output', function () {
    $fixtures = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/bast-template-html.json'), true);

    it('keeps everything the editor produces unchanged, with no problem', function (string $html) {
        expect(sanitizeTemplate($html))
            ->html->toBe($html)
            ->problems->toBe([]);
    })->with($fixtures['cases']);
});

describe('xss payloads', function () {
    it('stores only allowed markup, and sanitizing again changes nothing', function (string $payload) {
        $stored = sanitizeTemplate($payload)->html;

        expectOnlyTemplateMarkup($stored);
        expect(mb_strtolower($stored))
            ->not->toContain('<script')
            ->not->toContain('javascript:')
            ->not->toContain('data:')
            ->not->toContain(' on')
            ->not->toContain('href')
            ->and(sanitizeTemplate($stored)->html)->toBe($stored)
            ->and(BastTemplateHtml::forRender($stored, fn (string $uuid): ?string => $uuid === TEMPLATE_IMAGE ? 'kop.png' : null))->toBe($stored);
    })->with(xssPayloads(TEMPLATE_IMAGE, OTHER_IMAGE));

    it('keeps only text-align in a style', function (string $style, string $expected) {
        expect(sanitizeTemplate('<p style="'.$style.'">x</p>')->html)->toBe($expected);
    })->with([
        'alignment without spaces' => ['text-align:CENTER;', '<p style="text-align: center;">x</p>'],
        'another property' => ['color: red', '<p>x</p>'],
        'alignment plus another property' => ['text-align: center; background: url(https://evil.test/x.png)', '<p>x</p>'],
        'unknown alignment' => ['text-align: expression(alert(1))', '<p>x</p>'],
        'css escape' => ['text-align: c\\65 nter', '<p>x</p>'],
    ]);

    it('drops styles outside paragraphs and headings, and links', function () {
        expect(sanitizeTemplate('<table style="width: 100%"><tbody><tr><td style="text-align: center;" colspan="x" rowspan="999"><p>a</p></td></tr></tbody></table><p><a href="https://contoh.test">tautan</a></p>')->html)
            ->toBe('<table><tbody><tr><td colspan="1" rowspan="20"><p>a</p></td></tr></tbody></table><p>tautan</p>');
    });

    it('drops the editor\'s column widths', function () {
        expect(sanitizeTemplate('<table style="min-width: 50px"><colgroup><col style="min-width: 25px"><col></colgroup><tbody><tr><td colspan="1" rowspan="1" colwidth="100"><p>a</p></td></tr></tbody></table>')->html)
            ->toBe('<table><tbody><tr><td colspan="1" rowspan="1"><p>a</p></td></tr></tbody></table>');
    });
});

describe('template injection', function () {
    it('keeps template-language syntax as plain text, never a problem', function (string $text) {
        $html = '<p>'.htmlspecialchars($text, ENT_NOQUOTES).'</p>';

        expect(sanitizeTemplate($html))
            ->html->toBe($html)
            ->problems->toBe([]);
    })->with([
        'blade echo with an expression' => '{{ $workOrder->delete() }}',
        'blade echo of a function' => '{{ phpinfo() }}',
        'blade raw echo' => '{!! $user->password !!}',
        'blade directive' => '@php system("id") @endphp',
        'php open tag' => '<?php echo 1; ?>',
        'short echo tag' => '<?= $x ?>',
        'triple braces around text' => '{{{ x y }}}',
        'twig tag' => '{% for x in y %}{% endfor %}',
        'unclosed placeholder' => '{{nomor_bast',
        'unopened placeholder' => 'nomor_bast}}',
        'single braces' => '{nomor_bast}',
    ]);

    it('reports each unknown placeholder once', function () {
        expect(sanitizeTemplate('<p>{{nomor_bast}} {{password}} {{ Nomor_Bast }} {{password}}</p>')->problems)
            ->toBe(['Placeholder tidak dikenal: {{password}}, {{ Nomor_Bast }}.']);
    });

    it('refuses a placeholder in an attribute, even one sanitizing would drop', function (string $html) {
        expect(sanitizeTemplate($html)->problems)
            ->toBe(['Placeholder hanya boleh di dalam teks, tidak di atribut (mis. alamat atau teks alternatif gambar).']);
    })->with([
        'image source' => '<img src="{{nomor_bast}}">',
        'image alt' => '<img src="/attachments/'.TEMPLATE_IMAGE.'" alt="{{judul}}">',
        'link' => '<p><a href="https://contoh.test/{{nomor_wo}}">x</a></p>',
        'style' => '<p style="text-align: {{judul}}">x</p>',
        'unknown attribute' => '<p data-x="{{ judul }}">x</p>',
    ]);

    it('refuses a block placeholder that does not stand alone in a paragraph', function (string $html) {
        expect(sanitizeTemplate($html)->problems)
            ->toBe(['{{tabel_laporan_harian}} harus berdiri sendiri dalam satu paragraf.']);
    })->with([
        'with other text' => '<p>Laporan: {{tabel_laporan_harian}}</p>',
        'in bold' => '<p><strong>{{tabel_laporan_harian}}</strong></p>',
        'in a table' => '<table><tbody><tr><td><p>{{tabel_laporan_harian}}</p></td></tr></tbody></table>',
        'in a heading' => '<h2>{{tabel_laporan_harian}}</h2>',
    ]);

    it('refuses a placeholder split by formatting', function () {
        expect(sanitizeTemplate('<p>{{nomor_<strong>bast</strong>}}</p>')->problems)
            ->toBe(['Placeholder harus ditulis utuh, tanpa format berbeda di tengahnya.']);
    });

    it('knows every placeholder the picker offers', function () {
        $html = implode('', array_map(fn (BastPlaceholder $placeholder): string => '<p>'.$placeholder->token().'</p>', BastPlaceholder::cases()));

        expect(sanitizeTemplate($html)->problems)->toBe([]);
    });

    it('refuses a template nested deeper than the limit, before sanitizing it', function () {
        $depth = config()->integer('work_order.bast.template_max_depth');

        expect(sanitizeTemplate(str_repeat('<ul><li>', $depth).'x'.str_repeat('</li></ul>', $depth))->problems)
            ->toBe(["Template terlalu bertingkat (lebih dari {$depth} tingkat). Sederhanakan daftar atau tabel bersarang."])
            ->and(sanitizeTemplate(str_repeat('<ul><li>', 10).'x'.str_repeat('</li></ul>', 10))->problems)->toBe([]);
    });

    it('is empty without text or images', function (string $html, bool $empty) {
        expect(sanitizeTemplate($html)->isEmpty)->toBe($empty);
    })->with([
        'nothing' => ['', true],
        'empty paragraphs' => ['<p> </p><p></p>', true],
        'a rule' => ['<hr>', true],
        'an image' => ['<img src="/attachments/'.TEMPLATE_IMAGE.'">', false],
        'text' => ['<p>x</p>', false],
    ]);
});

describe('filling placeholders', function () {
    it('inserts values as escaped text', function (string $value, string $escaped) {
        expect(BastTemplateHtml::fill('<p>Judul: {{judul}}</p>', ['judul' => $value]))
            ->toBe('<p>Judul: '.$escaped.'</p>');
    })->with([
        'script' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
        'image with onerror' => ['<img src=x onerror=alert(1)>', '&lt;img src=x onerror=alert(1)&gt;'],
        'ampersand and quotes' => ['A & B "C"', 'A &amp; B "C"'],
        'closing tag' => ['</p><p>baru', '&lt;/p&gt;&lt;p&gt;baru'],
    ]);

    it('keeps line breaks of a value as <br>, the rest as text', function () {
        expect(BastTemplateHtml::fill('<p>Uraian: {{deskripsi}}.</p>', ['deskripsi' => "Baris <b>satu</b>\r\nbaris dua\n\nbaris empat"]))
            ->toBe('<p>Uraian: Baris &lt;b&gt;satu&lt;/b&gt;<br>baris dua<br><br>baris empat.</p>');
    });

    it('substitutes in one pass, so a value is never read as a placeholder', function () {
        expect(BastTemplateHtml::fill('<p>{{judul}} / {{nomor_wo}}</p>', ['judul' => '{{nomor_wo}} {{ $x }}', 'nomor_wo' => 'WO/1']))
            ->toBe('<p>{{nomor_wo}} {{ $x }} / WO/1</p>');
    });

    it('fills placeholders with spaces and leaves template syntax alone', function () {
        expect(BastTemplateHtml::fill('<p>{{ judul }} {{{judul}}} {!! judul !!} @php {{ $judul }}</p>', ['judul' => 'X']))
            ->toBe('<p>X {X} {!! judul !!} @php {{ $judul }}</p>');
    });

    it('replaces a block placeholder\'s paragraph with the nodes the application builds', function () {
        $html = BastTemplateHtml::fill(
            '<p>Laporan:</p><p>{{tabel_laporan_harian}}</p>',
            [],
            ['tabel_laporan_harian' => fn (Document $document): array => [$document->createElement('table')]],
        );

        expect($html)->toBe('<p>Laporan:</p><table></table>');
    });

    it('fills the text of table cells and headings', function () {
        expect(BastTemplateHtml::fill('<h1 style="text-align: center;">{{nomor_bast}}</h1><table><tbody><tr><td colspan="1" rowspan="1"><p>{{judul}}</p></td></tr></tbody></table>', ['nomor_bast' => 'BAST/1', 'judul' => 'J']))
            ->toBe('<h1 style="text-align: center;">BAST/1</h1><table><tbody><tr><td colspan="1" rowspan="1"><p>J</p></td></tr></tbody></table>');
    });
});
