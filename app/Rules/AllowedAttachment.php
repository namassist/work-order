<?php

namespace App\Rules;

use App\Support\Attachments\AttachmentCollection;
use App\Support\Attachments\AttachmentTypeDetector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Accepts a file only if its content is one of the collection's types. The
 * client's filename and MIME type are ignored. For a collection with
 * maxImageSide, an image's width and height (read from its header, never
 * decoded) must stay within it, so a small file cannot unpack into a huge
 * bitmap later (e.g. when a PDF engine renders it).
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
            $fail(__('Jenis berkas tidak diizinkan. Gunakan :types.', ['types' => $this->collection->typeList()]));

            return;
        }

        $maxSide = $this->collection->maxImageSide;

        if ($maxSide !== null && str_starts_with($type->value, 'image/')) {
            $size = @getimagesize((string) $value->getRealPath());

            if ($size === false || $size[0] > $maxSide || $size[1] > $maxSide) {
                $fail(__('Gambar terlalu besar. Maksimal :max × :max piksel.', ['max' => $maxSide]));
            }
        }
    }
}
