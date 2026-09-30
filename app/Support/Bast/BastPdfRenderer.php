<?php

namespace App\Support\Bast;

/**
 * Turns a BAST page (BastDocument) into PDF bytes. The engine must not
 * fetch any remote resource, read local files, or run scripts: everything
 * the page shows is embedded in it.
 */
interface BastPdfRenderer
{
    /**
     * @throws BastGenerationFailed when the engine cannot produce the PDF
     */
    public function render(string $html): string;
}
