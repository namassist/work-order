<?php

namespace App\Support\Html;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Parsing helpers shared by the sanitizers of user-written HTML (comments,
 * BAST templates), on PHP 8.4's HTML5 parser.
 */
final class HtmlFragment
{
    /** Elements removed with their content; anything else unknown keeps its text. */
    public const array DROPPED_ELEMENTS = [
        'script', 'style', 'template', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'svg', 'math', 'title', 'textarea', 'select', 'noembed', 'noframes', 'xmp', 'plaintext',
        'head', 'meta', 'link', 'base', 'video', 'audio', 'source', 'track', 'picture', 'canvas', 'map', 'area',
    ];

    /**
     * The <body> of a document holding the fragment.
     */
    public static function parse(string $html): Element
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html.'</body>', LIBXML_NOERROR);

        return $document->body;
    }

    /**
     * Remove the elements whose content must go with them. An allowlist
     * sanitizer cannot drop <style>, <title>, and other head elements in a
     * body, so it would keep their text.
     */
    public static function withoutDroppedElements(string $html): string
    {
        $body = self::parse($html);

        foreach (iterator_to_array($body->querySelectorAll(implode(',', self::DROPPED_ELEMENTS))) as $element) {
            $element->remove();
        }

        return $body->innerHTML;
    }

    public static function removeAttributes(Element $element): void
    {
        foreach ($element->getAttributeNames() as $attribute) {
            $element->removeAttribute($attribute);
        }
    }
}
