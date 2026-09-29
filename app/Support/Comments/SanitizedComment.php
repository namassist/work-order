<?php

namespace App\Support\Comments;

/**
 * A comment body after CommentHtml::sanitize(): the HTML safe to store and
 * render, its plain text (for length limits, previews, and notifications),
 * and the uploads its images show.
 */
final readonly class SanitizedComment
{
    /**
     * @param  list<string>  $imageUuids  media uuids of the images, each once, in order
     * @param  int  $imageCount  images shown, repeats included (what the per-comment limit counts)
     */
    public function __construct(
        public string $html,
        public string $text,
        public array $imageUuids,
        public int $imageCount,
    ) {}

    /**
     * Whether anything is left to show: text or an image.
     */
    public function hasContent(): bool
    {
        return $this->text !== '' || $this->imageUuids !== [];
    }
}
