<?php

namespace App\Enums;

/**
 * File types an attachment may have, identified by content (see
 * AttachmentTypeDetector). SVG, HTML, and macro-enabled Office files are
 * deliberately absent, so no collection can be configured to accept them.
 */
enum AttachmentType: string
{
    case Pdf = 'application/pdf';
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    case Xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * The extension a stored or downloaded file gets.
     */
    public function extension(): string
    {
        return match ($this) {
            self::Pdf => 'pdf',
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Docx => 'docx',
            self::Xlsx => 'xlsx',
        };
    }

    /**
     * Extensions users know the type by, for the file picker and messages.
     *
     * @return list<string>
     */
    public function knownExtensions(): array
    {
        return $this === self::Jpeg ? ['jpg', 'jpeg'] : [$this->extension()];
    }

    /**
     * Whether browsers may show the file inline. Everything else is always
     * downloaded.
     */
    public function isPreviewable(): bool
    {
        return in_array($this, [self::Pdf, self::Jpeg, self::Png, self::Webp], true);
    }
}
