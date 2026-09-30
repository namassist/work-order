<?php

namespace App\Actions\WorkOrders\Transitions;

use App\Actions\Attachments\AddAttachment;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrderBast;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use LogicException;

/**
 * Stores a generated BAST PDF in one of the BAST's collections through
 * AddAttachment: private disk, type detected from the content, and the
 * stored file deleted again if the caller's transaction rolls back.
 */
class StoreBastPdf
{
    public function __construct(private readonly AddAttachment $attachments) {}

    public function handle(WorkOrderBast $bast, string $collection, string $pdf, User $user): Media
    {
        $rules = $bast->attachmentCollection($collection) ?? throw new LogicException("A BAST has no collection {$collection}.");
        File::ensureDirectoryExists(storage_path('app/dompdf/tmp'));
        $path = (string) tempnam(storage_path('app/dompdf/tmp'), 'bast-');
        File::put($path, $pdf);

        $name = str_replace('/', '-', $bast->number).($collection === WorkOrderBast::DRAFT ? '-draf' : '').'.pdf';

        try {
            return $this->attachments->handle($bast, $rules, new UploadedFile($path, $name, 'application/pdf', null, true), $user);
        } finally {
            File::delete($path);
        }
    }
}
