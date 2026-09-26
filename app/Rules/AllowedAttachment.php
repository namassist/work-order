<?php

namespace App\Rules;

use App\Support\Attachments\AttachmentCollection;
use App\Support\Attachments\AttachmentTypeDetector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Accepts a file only if its content is one of the collection's types. The
 * client's filename and MIME type are ignored.
 */
class AllowedAttachment implements ValidationRule
{
    public function __construct(private readonly AttachmentCollection $collection) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = $value instanceof UploadedFile && $value->isValid()
            ? app(AttachmentTypeDetector::class)->detect($value->getRealPath())
            : null;

        if ($type === null || ! $this->collection->accepts($type)) {
            $fail(__('Jenis berkas :attribute tidak diizinkan. Gunakan :types.', ['types' => $this->collection->typeList()]));
        }
    }
}
