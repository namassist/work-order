<?php

namespace App\Support\Bast;

use App\Models\BastTemplate;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * The template's images (the letterhead) as the application embeds them:
 * name, detected type, and content in base64. The draft shows them through
 * their attachment route; a published version keeps this copy, so deleting
 * an upload later never changes it.
 */
final class BastTemplateImages
{
    /**
     * @param  list<string>|null  $only  the uuids to include, or every image when null
     * @return array<string, array{name: string, mime: string, data: string}>
     */
    public static function of(BastTemplate $template, ?array $only = null): array
    {
        $images = [];

        foreach ($template->attachmentsIn(BastTemplate::IMAGES) as $media) {
            /** @var Media $media */
            if ($only !== null && ! in_array($media->uuid, $only, true)) {
                continue;
            }

            $content = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());

            if ($content === null) {
                continue;
            }

            $images[(string) $media->uuid] = [
                'name' => $media->name,
                'mime' => $media->mime_type,
                'data' => base64_encode($content),
            ];
        }

        return $images;
    }

    /**
     * The file names of the template's images, by uuid: what the sanitizer
     * asks when it decides which images the draft may show.
     *
     * @return array<string, string>
     */
    public static function names(BastTemplate $template): array
    {
        return $template->attachmentsIn(BastTemplate::IMAGES)
            ->mapWithKeys(fn (Media $media): array => [(string) $media->uuid => $media->name])
            ->all();
    }
}
