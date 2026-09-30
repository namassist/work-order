<?php

namespace App\Support\Bast;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Throwable;

/**
 * Renders BAST pages with dompdf (pure PHP; FLOW.md §8). Locked down:
 * remote loading, JavaScript, and inline PHP are off, the only protocol
 * allowed is data:, so the engine neither fetches a URL nor reads a local
 * file, and its chroot is an empty directory. Fonts (DejaVu, bundled) are
 * cached under storage/app/dompdf, which must be writable.
 */
class DompdfBastPdfRenderer implements BastPdfRenderer
{
    public function render(string $html): string
    {
        $dompdf = new Dompdf($this->options());
        $this->ignoreEngineWarnings();

        try {
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $output = $dompdf->output();
        } catch (Throwable $exception) {
            throw new BastGenerationFailed(__('BAST gagal dibuat.'), $exception->getCode(), previous: $exception);
        } finally {
            restore_error_handler();
        }

        if (! str_starts_with($output, '%PDF-')) {
            throw new BastGenerationFailed(__('BAST gagal dibuat.'));
        }

        return $output;
    }

    /**
     * The engine reports a resource it refuses (a remote URL, a local file)
     * as a PHP warning and carries on without it; some of its code paths
     * read a missing array key after refusing. Laravel turns warnings into
     * exceptions, so warnings raised inside the engine's own code are
     * ignored while it renders: the refused resource is simply not drawn.
     * Anything else goes to the previous (Laravel's) handler.
     */
    private function ignoreEngineWarnings(): void
    {
        // vendor/dompdf: the engine and its font and SVG libraries.
        $engine = dirname((string) new ReflectionClass(Dompdf::class)->getFileName(), 3).DIRECTORY_SEPARATOR;
        $previous = null;

        $previous = set_error_handler(
            function (int $level, string $message, string $file, int $line) use (&$previous, $engine): bool {
                if (str_starts_with($file, $engine)) {
                    return true;
                }

                return is_callable($previous) && (bool) $previous($level, $message, $file, $line);
            },
            E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED,
        );
    }

    private function options(): Options
    {
        $root = storage_path('app/dompdf');

        foreach (['fonts', 'tmp', 'chroot'] as $directory) {
            File::ensureDirectoryExists("{$root}/{$directory}");
        }

        return new Options([
            'isRemoteEnabled' => false,
            'isJavascriptEnabled' => false,
            'isPhpEnabled' => false,
            'isPdfAEnabled' => false,
            'allowedProtocols' => ['data://' => ['rules' => []]],
            'allowedRemoteHosts' => [],
            'chroot' => ["{$root}/chroot"],
            'fontDir' => "{$root}/fonts",
            'fontCache' => "{$root}/fonts",
            'tempDir' => "{$root}/tmp",
            'defaultFont' => 'DejaVu Sans',
            'defaultPaperSize' => 'a4',
            'isFontSubsettingEnabled' => true,
        ]);
    }
}
