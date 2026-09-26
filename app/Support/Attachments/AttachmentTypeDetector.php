<?php

namespace App\Support\Attachments;

use App\Enums\AttachmentType;
use finfo;
use ZipArchive;

/**
 * Identifies an attachment's type from its content, never from the client's
 * filename or MIME type, so a renamed executable is not accepted as a PDF.
 *
 * Office files are ZIP packages, and libmagic reports macro-enabled ones
 * (docm, xlsm) as plain docx/xlsx. So every ZIP-family result is opened and
 * accepted only when its single main part has exactly the docx or xlsx
 * content type and the package carries no VBA project.
 */
class AttachmentTypeDetector
{
    /**
     * Main part content types of the accepted Office formats.
     *
     * @var array<string, AttachmentType>
     */
    private const array OOXML_MAIN_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml' => AttachmentType::Docx,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml' => AttachmentType::Xlsx,
    ];

    /**
     * MIME types libmagic may report for an Office package.
     *
     * @var list<string>
     */
    private const array ZIP_FAMILY = [
        'application/zip',
        'application/x-zip-compressed',
        'application/octet-stream',
        AttachmentType::Docx->value,
        AttachmentType::Xlsx->value,
    ];

    /**
     * [Content_Types].xml is a few KB at most; refuse to read more.
     */
    private const int MAX_CONTENT_TYPES_BYTES = 1024 * 1024;

    /**
     * The attachment type of the file, or null if it is not an allowed type.
     */
    public function detect(string $path): ?AttachmentType
    {
        $mimeType = new finfo(FILEINFO_MIME_TYPE)->file($path);

        if ($mimeType === false) {
            return null;
        }

        if (in_array($mimeType, self::ZIP_FAMILY, true)) {
            return $this->detectOfficePackage($path);
        }

        $type = AttachmentType::tryFrom($mimeType);

        return $type?->isPreviewable() ? $type : null;
    }

    private function detectOfficePackage(string $path): ?AttachmentType
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $contentTypes = $zip->getFromName('[Content_Types].xml', self::MAX_CONTENT_TYPES_BYTES);

            if (! is_string($contentTypes) || $this->hasVbaProject($zip)) {
                return null;
            }

            $mainTypes = $this->mainPartContentTypes($contentTypes);

            if (count($mainTypes) !== 1) {
                return null;
            }

            return self::OOXML_MAIN_TYPES[$mainTypes[0]] ?? null;
        } finally {
            $zip->close();
        }
    }

    private function hasVbaProject(ZipArchive $zip): bool
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            if (str_ends_with(strtolower((string) $zip->getNameIndex($index)), 'vbaproject.bin')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Content types of the package's main document parts (…main+xml). The
     * macro-enabled and template variants match too, so they are counted and
     * rejected rather than skipped.
     *
     * @return list<string>
     */
    private function mainPartContentTypes(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, options: LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($document === false) {
            return [];
        }

        $types = [];

        foreach ($document->children() as $entry) {
            $contentType = strtolower((string) $entry['ContentType']);

            if ($entry->getName() === 'Override' && str_ends_with($contentType, '.main+xml')) {
                $types[] = $contentType;
            }
        }

        return $types;
    }
}
