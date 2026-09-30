<?php

use App\Support\Bast\BastDocument;
use App\Support\Bast\BastDocumentKind;
use App\Support\Bast\BastPdfRenderer;
use App\Support\Bast\BastValues;
use App\Support\Bast\DompdfBastPdfRenderer;

/*
| The PDF engine never fetches a remote resource and never reads a local
| file (FLOW.md §8): whatever a page references, only images embedded as
| data: URIs appear. A local listener proves no connection is attempted.
*/

function renderPdf(string $body): string
{
    return new DompdfBastPdfRenderer()->render('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$body.'</body></html>');
}

function pdfHasImage(string $pdf): bool
{
    return str_contains($pdf, '/Subtype /Image');
}

it('is the renderer the application uses', function () {
    expect(app(BastPdfRenderer::class))->toBeInstanceOf(DompdfBastPdfRenderer::class);
});

it('renders a PDF with Indonesian text', function () {
    expect(renderPdf('<h1>BERITA ACARA SERAH TERIMA</h1><p>1 September 2026 – 25 September 2026</p>'))
        ->toStartWith('%PDF-');
});

it('embeds a PNG or JPEG given as a data URI', function (string $fixture, string $mime) {
    $data = base64_encode((string) file_get_contents(base_path('tests/Fixtures/attachments/'.$fixture)));

    expect(pdfHasImage(renderPdf('<img src="data:'.$mime.';base64,'.$data.'">')))->toBeTrue();
})->with([
    'png' => ['foto.png', 'image/png'],
    'jpeg' => ['foto.jpg', 'image/jpeg'],
]);

it('never connects to a remote resource the page references', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    expect($server)->not->toBeFalse($errorMessage);
    $address = 'http://'.stream_socket_get_name($server, false);
    // Were the engine to connect, it would stall reading; keep that short.
    $timeout = ini_set('default_socket_timeout', '2');

    try {
        $pdf = renderPdf(
            '<link rel="stylesheet" href="'.$address.'/link.css">'
            .'<style>@import url("'.$address.'/import.css");'
            .'@font-face { font-family: "Remote"; src: url("'.$address.'/font.ttf"); }'
            .'p { font-family: "Remote"; background-image: url("'.$address.'/background.png"); }'
            .'</style>'
            .'<p style="background: url('.$address.'/inline.png)">teks</p>'
            .'<img src="'.$address.'/image.png">'
            .'<img src="https://example.com/image.png">'
            .'<object data="'.$address.'/object.pdf"></object>'
            .'<iframe src="'.$address.'/frame.html"></iframe>'
            .'<svg><image href="'.$address.'/svg.png"/></svg>'
            .'<img src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><image href="'.$address.'/svg-in-data.png" width="10" height="10"/></svg>').'">',
        );

        expect($pdf)->toStartWith('%PDF-')
            ->and(@stream_socket_accept($server, 0))->toBeFalse();
    } finally {
        ini_set('default_socket_timeout', (string) $timeout);
        fclose($server);
    }
});

it('never reads a local file the page references', function (string $reference) {
    expect(pdfHasImage(renderPdf('<img src="'.$reference.'"><p style="background-image: url('.$reference.')">x</p>')))->toBeFalse();
})->with([
    'absolute path' => fn (): string => base_path('tests/Fixtures/attachments/foto.png'),
    'file url' => fn (): string => 'file://'.base_path('tests/Fixtures/attachments/foto.png'),
    'relative path' => '../../../tests/Fixtures/attachments/foto.png',
    'php stream' => 'php://filter/resource='.'/etc/passwd',
    'phar' => 'phar:///tmp/x.phar/gambar.png',
]);

it('runs no script in the page', function () {
    $pdf = renderPdf('<script type="text/php">file_put_contents(sys_get_temp_dir()."/bast-php-ran", "1");</script><script>app.alert(1)</script><p>x</p>');

    expect($pdf)->not->toContain('/JavaScript')
        ->not->toContain('/JS')
        ->and(file_exists(sys_get_temp_dir().'/bast-php-ran'))->toBeFalse();
});

it('leaves out an embedded image larger than the pixel limit', function () {
    config(['work_order.bast.images.max_side_px' => 100]);
    $image = static function (int $width): string {
        ob_start();
        imagepng(imagecreatetruecolor($width, 10));

        return base64_encode((string) ob_get_clean());
    };
    $values = new BastValues([], []);
    $images = [
        'besar' => ['name' => 'besar.png', 'mime' => 'image/png', 'data' => $image(101)],
        'pas' => ['name' => 'pas.png', 'mime' => 'image/png', 'data' => $image(100)],
    ];

    // The sanitizer keeps only attachment routes, so the document is built from those.
    $html = BastDocument::html('<img src="/attachments/0192f3a4-5b6c-7d8e-9f01-000000000001"><img src="/attachments/0192f3a4-5b6c-7d8e-9f01-000000000002">', [
        '0192f3a4-5b6c-7d8e-9f01-000000000001' => $images['besar'],
        '0192f3a4-5b6c-7d8e-9f01-000000000002' => $images['pas'],
    ], $values, BastDocumentKind::Final);

    expect(substr_count($html, '<img'))->toBe(1)
        ->and($html)->toContain('data:image/png;base64,'.$images['pas']['data']);
});
