<?php

namespace App\Support\Bast;

/**
 * A BAST template after BastTemplateHtml::sanitize(): the HTML to store, the
 * uploads its images show, and why it may not be saved (empty when it may).
 */
final readonly class SanitizedBastTemplate
{
    /**
     * @param  list<string>  $imageUuids
     * @param  list<string>  $problems
     */
    public function __construct(
        public string $html,
        public array $imageUuids,
        public array $problems,
        public bool $isEmpty,
    ) {}
}
