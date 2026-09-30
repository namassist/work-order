<?php

namespace App\Support\Bast;

use App\Support\Html\HtmlFragment;
use Closure;
use Dom\Document;
use Dom\Element;
use Dom\Node;
use Dom\Text;
use Dom\XPath;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The HTML of the BAST template (FLOW.md §9). Sanitized on every save and
 * again before it is rendered, to exactly what the template editor
 * (BastTemplateEditor.vue) produces: paragraphs, line breaks, headings
 * (h1–h3), bold, italic, underline, strike, lists, horizontal rules,
 * tables, text alignment, and images of the template's own uploads. No
 * links, no styles but text-align, no other attribute.
 *
 * Placeholders ({{key}}, BastPlaceholder) are plain text here: sanitize()
 * reports unknown ones, ones in attributes, and misplaced block
 * placeholders as problems, and fill() replaces them in text nodes only,
 * in one pass, so every value is escaped and never read as a placeholder
 * or as markup. Nothing is compiled or evaluated.
 */
final class BastTemplateHtml
{
    /** The only image source kept: the attachment route of one upload. */
    private const string IMAGE_SOURCE = '#\A/attachments/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\z#i';

    /** Blocks that may be aligned, and how. */
    private const array ALIGNABLE = ['P', 'H1', 'H2', 'H3'];

    private const array ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    private const string ALIGNMENT_STYLE = '/\A\s*text-align\s*:\s*([a-z]+)\s*;?\s*\z/i';

    /** The widest a merged table cell may be, in columns or rows. */
    private const int MAX_SPAN = 20;

    private static ?HtmlSanitizer $allowlist = null;

    /**
     * Sanitize a template sent by the editor.
     *
     * @param  Closure(string): ?string  $imageName  given a (lowercase) media uuid, the file name of an image the template may show, or null to drop it
     */
    public static function sanitize(string $html, Closure $imageName): SanitizedBastTemplate
    {
        $raw = HtmlFragment::parse($html);
        $maxDepth = config()->integer('work_order.bast.template_max_depth');

        // Deep nesting is what makes sanitizing costly; no BAST layout needs it.
        if (self::depthOf($raw) > $maxDepth) {
            return new SanitizedBastTemplate('', [], [__('Template terlalu bertingkat (lebih dari :max tingkat). Sederhanakan daftar atau tabel bersarang.', ['max' => $maxDepth])], true);
        }

        $problems = self::placeholdersInAttributes($raw);
        $body = HtmlFragment::parse(self::allowlist()->sanitize(HtmlFragment::withoutDroppedElements($html)));
        $images = [];

        foreach (iterator_to_array($body->querySelectorAll('*')) as $element) {
            if ($element->tagName === 'IMG') {
                $uuid = self::keepImage($element, $imageName);

                if ($uuid !== null) {
                    $images[] = $uuid;
                }
            } elseif ($element->tagName === 'TD' || $element->tagName === 'TH') {
                self::normalizeCell($element);
            } else {
                self::normalizeAlignment($element);
            }
        }

        $isEmpty = trim((string) $body->textContent) === '' && $images === [];

        return new SanitizedBastTemplate(
            $body->innerHTML,
            array_values(array_unique($images)),
            [...$problems, ...self::placeholderProblems($body)],
            $isEmpty,
        );
    }

    /**
     * Sanitize stored template HTML again before it is rendered, as defense
     * in depth, keeping only the images the given names allow.
     *
     * @param  Closure(string): ?string  $imageName
     */
    public static function forRender(string $html, Closure $imageName): string
    {
        return self::sanitize($html, $imageName)->html;
    }

    /**
     * Replace the placeholders of sanitized template HTML: inline ones by
     * their value as text (its line breaks as <br>), block ones (alone in
     * their paragraph) by the nodes the application builds. One pass over
     * the text nodes, so a value that looks like a placeholder or like
     * markup stays text. A token without a value is left as it is.
     *
     * @param  array<string, string>  $values  inline values by placeholder key
     * @param  array<string, Closure(Document): list<Node>>  $blocks  block builders by placeholder key
     */
    public static function fill(string $html, array $values, array $blocks = []): string
    {
        $body = HtmlFragment::parse($html);

        foreach (self::textNodes($body) as $text) {
            $block = self::blockKeyOf($text);

            if ($block !== null && isset($blocks[$block])) {
                /** @var Element $paragraph */
                $paragraph = $text->parentNode;
                $paragraph->replaceWith(...$blocks[$block]($body->ownerDocument));

                continue;
            }

            if (preg_match(BastPlaceholder::PATTERN, $text->data) === 1) {
                $text->replaceWith(...self::filledNodes($text->data, $values, $body->ownerDocument));
            }
        }

        return $body->innerHTML;
    }

    /**
     * A text with its placeholders replaced, as text nodes (escaped when
     * serialized) with a <br> for each line break inside a value.
     *
     * @param  array<string, string>  $values
     * @return list<Node|string>
     */
    private static function filledNodes(string $text, array $values, Document $document): array
    {
        preg_match_all(BastPlaceholder::PATTERN, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $nodes = [];
        $buffer = '';
        $offset = 0;

        foreach ($matches as $match) {
            [$token, $position] = $match[0];
            $buffer .= substr($text, $offset, $position - $offset);
            $offset = $position + strlen($token);
            $value = $values[$match[1][0]] ?? null;

            if ($value === null) {
                $buffer .= $token;

                continue;
            }

            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $value));
            $buffer .= array_shift($lines);

            foreach ($lines as $line) {
                $nodes[] = $buffer;
                $nodes[] = $document->createElement('br');
                $buffer = $line;
            }
        }

        $nodes[] = $buffer.substr($text, $offset);

        return array_values(array_filter($nodes, fn (Node|string $node): bool => $node !== ''));
    }

    private static function allowlist(): HtmlSanitizer
    {
        if (self::$allowlist instanceof HtmlSanitizer) {
            return self::$allowlist;
        }

        $config = new HtmlSanitizerConfig()
            // Unknown formatting (a pasted <span>, <div>, <a>) keeps its text.
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowElement('p', ['style'])
            ->allowElement('h1', ['style'])
            ->allowElement('h2', ['style'])
            ->allowElement('h3', ['style'])
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('hr')
            ->allowElement('table')
            ->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th', ['colspan', 'rowspan'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('img', ['src', 'alt'])
            ->allowLinkSchemes([])
            ->allowRelativeLinks(false)
            ->allowMediaSchemes([])
            ->allowRelativeMedias()
            // The request limits the size; this only guards against truncating a stored template.
            ->withMaxInputLength(4 * config()->integer('work_order.bast.template_max_html_bytes'));

        return self::$allowlist = new HtmlSanitizer($config);
    }

    /**
     * Placeholders are allowed only in text: one in any attribute of what
     * was sent (an image's source or alt text, a link) refuses the template,
     * even if sanitizing would drop that attribute.
     *
     * @return list<string>
     */
    private static function placeholdersInAttributes(Element $body): array
    {
        foreach ($body->querySelectorAll('*') as $element) {
            foreach ($element->attributes as $attribute) {
                if (preg_match(BastPlaceholder::PATTERN, (string) $attribute->value) === 1) {
                    return [__('Placeholder hanya boleh di dalam teks, tidak di atribut (mis. alamat atau teks alternatif gambar).')];
                }
            }
        }

        return [];
    }

    /**
     * How deeply elements nest below the body, iteratively (a recursive walk
     * of a hostile document could exhaust the stack).
     */
    private static function depthOf(Element $body): int
    {
        $deepest = 0;
        $stack = [[$body, 0]];

        while ($stack !== []) {
            [$element, $depth] = array_pop($stack);
            $deepest = max($deepest, $depth);

            for ($child = $element->firstElementChild; $child instanceof Element; $child = $child->nextElementSibling) {
                $stack[] = [$child, $depth + 1];
            }
        }

        return $deepest;
    }

    /**
     * Unknown placeholders, block placeholders not alone in a paragraph,
     * and placeholders split by formatting (which would print as text).
     *
     * @return list<string>
     */
    private static function placeholderProblems(Element $body): array
    {
        $problems = [];
        $unknown = [];
        $misplacedBlocks = [];
        $found = 0;

        foreach (self::textNodes($body) as $text) {
            preg_match_all(BastPlaceholder::PATTERN, $text->data, $matches);
            $found += count($matches[0]);

            foreach ($matches[1] as $index => $key) {
                $placeholder = BastPlaceholder::tryFrom($key);

                if (! $placeholder instanceof BastPlaceholder) {
                    $unknown[] = $matches[0][$index];
                } elseif ($placeholder->isBlock() && self::blockKeyOf($text) !== $key) {
                    $misplacedBlocks[] = $placeholder->token();
                }
            }
        }

        if ($unknown !== []) {
            $problems[] = __('Placeholder tidak dikenal: :placeholders.', ['placeholders' => implode(', ', array_unique($unknown))]);
        }

        foreach (array_unique($misplacedBlocks) as $token) {
            $problems[] = __(':placeholder harus berdiri sendiri dalam satu paragraf.', ['placeholder' => $token]);
        }

        if (preg_match_all(BastPlaceholder::PATTERN, (string) $body->textContent) > $found) {
            $problems[] = __('Placeholder harus ditulis utuh, tanpa format berbeda di tengahnya.');
        }

        return $problems;
    }

    /**
     * The block placeholder this text node stands for: it is the only
     * content of a top-level paragraph, and nothing but the token.
     */
    private static function blockKeyOf(Text $text): ?string
    {
        $paragraph = $text->parentNode;

        if (! $paragraph instanceof Element || $paragraph->tagName !== 'P' || ! $paragraph->parentNode instanceof Element
            || $paragraph->parentNode->tagName !== 'BODY' || $paragraph->childNodes->length !== 1) {
            return null;
        }

        if (preg_match('/\A\s*\{\{\s*([A-Za-z0-9_]+)\s*\}\}\s*\z/', $text->data, $match) !== 1) {
            return null;
        }

        return BastPlaceholder::tryFrom($match[1])?->isBlock() === true ? $match[1] : null;
    }

    /**
     * @return list<Text>
     */
    private static function textNodes(Element $body): array
    {
        $nodes = [];

        foreach (new XPath($body->ownerDocument)->query('.//text()', $body) as $node) {
            if ($node instanceof Text) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Keep the image only if its source is one upload the template may
     * show, rewritten to the canonical path with alt text (the file name by
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
     * Only headings and paragraphs keep a style, and only "text-align: x"
     * with a known alignment, written the way the editor writes it.
     */
    private static function normalizeAlignment(Element $element): void
    {
        $style = $element->getAttribute('style');
        HtmlFragment::removeAttributes($element);

        if ($style === null || ! in_array($element->tagName, self::ALIGNABLE, true)
            || preg_match(self::ALIGNMENT_STYLE, $style, $match) !== 1) {
            return;
        }

        $alignment = mb_strtolower($match[1]);

        if (in_array($alignment, self::ALIGNMENTS, true)) {
            $element->setAttribute('style', 'text-align: '.$alignment.';');
        }
    }

    /**
     * A table cell spans 1 to MAX_SPAN columns and rows, written colspan
     * then rowspan, as the editor does.
     */
    private static function normalizeCell(Element $cell): void
    {
        $spans = [];

        foreach (['colspan', 'rowspan'] as $attribute) {
            $value = trim((string) $cell->getAttribute($attribute));
            $spans[$attribute] = preg_match('/\A[1-9][0-9]{0,3}\z/', $value) === 1 ? min((int) $value, self::MAX_SPAN) : 1;
        }

        HtmlFragment::removeAttributes($cell);

        foreach ($spans as $attribute => $value) {
            $cell->setAttribute($attribute, (string) $value);
        }
    }
}
