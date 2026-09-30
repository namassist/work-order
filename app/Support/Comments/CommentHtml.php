<?php

namespace App\Support\Comments;

use App\Support\Html\HtmlFragment;
use Closure;
use Dom\Element;
use Dom\Node;
use Dom\Text;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The HTML of work order comments (FLOW.md §9). Every body is sanitized on
 * write, and again before it is rendered, to exactly what the comment editor
 * (CommentEditor.vue) produces: paragraphs, line breaks, bold, italic,
 * strike, inline code, lists, blockquotes, links, and images.
 *
 * Two passes: symfony/html-sanitizer's allowlist (parsed with PHP's HTML5
 * parser), then a DOM pass that keeps only images of uploads the comment may
 * show, unwraps links left without a target, and writes attributes in the
 * editor's order, so the editor's output is stored byte for byte.
 */
final class CommentHtml
{
    /** The most HTML a comment may send, in bytes; checked before sanitizing. */
    public const int MAX_HTML_BYTES = 65_536;

    /** The most plain text a comment may hold, in characters. */
    public const int MAX_TEXT_LENGTH = 5000;

    public const string LINK_REL = 'noopener noreferrer nofollow';

    /** The only image source kept: the attachment route of one upload. */
    private const string IMAGE_SOURCE = '#\A/attachments/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\z#i';

    private static ?HtmlSanitizer $allowlist = null;

    /**
     * Sanitize a body sent by a user.
     *
     * @param  Closure(string): ?string  $imageName  given a (lowercase) media uuid, the file name of an upload the comment may show, or null to drop the image
     */
    public static function sanitize(string $html, Closure $imageName): SanitizedComment
    {
        $body = HtmlFragment::parse(self::allowlist()->sanitize(HtmlFragment::withoutDroppedElements($html)));
        $images = [];

        foreach (iterator_to_array($body->querySelectorAll('img')) as $image) {
            $uuid = self::keepImage($image, $imageName);

            if ($uuid !== null) {
                $images[] = $uuid;
            }
        }

        foreach (iterator_to_array($body->querySelectorAll('a')) as $link) {
            self::normalizeLink($link);
        }

        return new SanitizedComment($body->innerHTML, self::plainText($body), array_values(array_unique($images)), count($images));
    }

    /**
     * Sanitize a stored body again before it is rendered, as defense in
     * depth: which uploads its images show was decided when it was saved.
     */
    public static function forDisplay(string $html): string
    {
        return self::sanitize($html, fn (): string => '')->html;
    }

    /**
     * The HTML of a plain-text comment: escaped, with blank lines starting a
     * new paragraph and single line breaks kept as <br>.
     */
    public static function fromPlainText(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return '';
        }

        $body = HtmlFragment::parse('');

        foreach (preg_split('/\n{2,}/', $text) ?: [] as $paragraph) {
            $element = $body->ownerDocument->createElement('p');

            foreach (explode("\n", $paragraph) as $index => $line) {
                if ($index > 0) {
                    $element->append($body->ownerDocument->createElement('br'));
                }

                $element->append($line);
            }

            $body->append($element);
        }

        return $body->innerHTML;
    }

    private static function allowlist(): HtmlSanitizer
    {
        if (self::$allowlist instanceof HtmlSanitizer) {
            return self::$allowlist;
        }

        $config = new HtmlSanitizerConfig()
            // Unknown formatting (a pasted <span>, <div>, <h1>) keeps its text.
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('s')
            ->allowElement('code')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('a', ['href'])
            ->allowElement('img', ['src', 'alt'])
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            ->allowMediaSchemes([])
            ->allowRelativeMedias()
            // The request limits the size; this only guards against truncating a stored body.
            ->withMaxInputLength(4 * self::MAX_HTML_BYTES);

        return self::$allowlist = new HtmlSanitizer($config);
    }

    /**
     * Keep the image only if its source is one upload the comment may show,
     * rewritten to the canonical path with alt text (the file name by
     * default). Returns the upload's uuid, or null once removed.
     *
     * @param  Closure(string): ?string  $imageName
     */
    private static function keepImage(Element $image, Closure $imageName): ?string
    {
        $name = null;

        if (preg_match(self::IMAGE_SOURCE, (string) $image->getAttribute('src'), $matches) === 1) {
            $uuid = mb_strtolower($matches[1]);
            $name = $imageName($uuid);
        }

        if (! isset($uuid) || $name === null) {
            $image->remove();

            return null;
        }

        $alt = trim((string) $image->getAttribute('alt'));
        HtmlFragment::removeAttributes($image);
        $image->setAttribute('src', '/attachments/'.$uuid);
        $image->setAttribute('alt', $alt !== '' ? $alt : $name);

        return $uuid;
    }

    /**
     * A link opens in a new tab without access to this page; one whose
     * target was dropped keeps only its text.
     */
    private static function normalizeLink(Element $link): void
    {
        $href = $link->getAttribute('href');

        if ($href === null || $href === '') {
            $link->replaceWith(...iterator_to_array($link->childNodes));

            return;
        }

        HtmlFragment::removeAttributes($link);
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', self::LINK_REL);
        $link->setAttribute('href', $href);
    }

    /**
     * The words of the comment with its line structure: a blank line between
     * blocks, "- " or "1. " before list items, images left out.
     */
    private static function plainText(Element $body): string
    {
        $lines = array_map(trim(...), explode("\n", self::textOf($body)));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    private static function textOf(Node $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= match (true) {
                $child instanceof Text => (string) preg_replace('/\s+/', ' ', (string) $child->textContent),
                ! $child instanceof Element => '',
                $child->tagName === 'BR' => "\n",
                $child->tagName === 'IMG' => '',
                $child->tagName === 'UL', $child->tagName === 'OL' => "\n\n".self::listText($child)."\n\n",
                $child->tagName === 'P', $child->tagName === 'BLOCKQUOTE', $child->tagName === 'LI' => "\n\n".self::textOf($child)."\n\n",
                default => self::textOf($child),
            };
        }

        return $text;
    }

    private static function listText(Element $list): string
    {
        $items = [];

        foreach ($list->childNodes as $item) {
            if (! $item instanceof Element) {
                continue;
            }

            $marker = $list->tagName === 'OL' ? (count($items) + 1).'. ' : '- ';
            $items[] = $marker.preg_replace("/\n{2,}/", "\n", trim(self::textOf($item)));
        }

        return implode("\n", $items);
    }
}
