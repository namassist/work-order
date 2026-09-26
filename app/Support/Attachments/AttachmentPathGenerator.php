<?php

namespace App\Support\Attachments;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Stores every file in a directory named after its random media UUID, so
 * paths reveal neither the database id nor the parent record.
 */
class AttachmentPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $media->uuid.'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $media->uuid.'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $media->uuid.'/responsive-images/';
    }
}
