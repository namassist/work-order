<?php

use App\Support\Comments\CommentHtml;
use App\Support\Comments\SanitizedComment;
use Dom\HTMLDocument;

const ALLOWED_IMAGE = '0192f3a4-5b6c-7d8e-9f01-23456789abcd';
const FOREIGN_IMAGE = '0192f3a4-5b6c-7d8e-9f01-ffffffffffff';

/**
 * Sanitizes as a comment that may show only ALLOWED_IMAGE (named foto.jpg).
 */
function sanitizeComment(string $html): SanitizedComment
{
    return CommentHtml::sanitize($html, fn (string $uuid): ?string => $uuid === ALLOWED_IMAGE ? 'foto.jpg' : null);
}

/**
 * Asserts that the HTML holds only the editor's elements and attributes,
 * with links and images in their only allowed forms.
 */
function expectOnlyAllowedMarkup(string $html): void
{
    $document = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html.'</body>', LIBXML_NOERROR);
    $allowed = [
        'P' => [], 'BR' => [], 'STRONG' => [], 'EM' => [], 'S' => [], 'CODE' => [],
        'UL' => [], 'OL' => [], 'LI' => [], 'BLOCKQUOTE' => [],
        'A' => ['target', 'rel', 'href'], 'IMG' => ['src', 'alt'],
    ];

    foreach ($document->body->querySelectorAll('*') as $element) {
        expect(array_key_exists($element->tagName, $allowed))->toBeTrue("<{$element->tagName}> is not allowed");

        foreach ($element->attributes as $attribute) {
            expect($allowed[$element->tagName])->toContain($attribute->name);
        }

        if ($element->tagName === 'A') {
            expect($element->getAttribute('href'))->toMatch('#\A(https?://|mailto:)#')
                ->and($element->getAttribute('target'))->toBe('_blank')
                ->and($element->getAttribute('rel'))->toBe('noopener noreferrer nofollow');
        }

        if ($element->tagName === 'IMG') {
            expect($element->getAttribute('src'))->toBe('/attachments/'.ALLOWED_IMAGE);
        }
    }
}

describe('editor output', function () {
    $fixtures = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/comment-html.json'), true);

    it('keeps everything the editor produces unchanged', function (string $html) {
        expect(sanitizeComment($html)->html)->toBe($html);
    })->with($fixtures['cases']);
});

describe('xss payloads', function () {
    it('stores only allowed markup, and sanitizing again changes nothing', function (string $payload) {
        $stored = sanitizeComment($payload)->html;

        expectOnlyAllowedMarkup($stored);
        expect(mb_strtolower($stored))
            ->not->toContain('<script')
            ->not->toContain('javascript:')
            ->not->toContain('data:')
            ->not->toContain(' on')
            ->not->toContain('style=')
            ->and(sanitizeComment($stored)->html)->toBe($stored)
            // The page renders only the display pass of what was stored.
            ->and(CommentHtml::forDisplay($stored))->toBe($stored);
    })->with(xssPayloads(ALLOWED_IMAGE, FOREIGN_IMAGE));

    it('keeps the text of a dropped link', function () {
        expect(sanitizeComment('<p><a href="javascript:alert(1)">klik</a> di sini</p>')->html)
            ->toBe('<p>klik di sini</p>');
    });

    it('keeps the text of tags it does not know', function () {
        expect(sanitizeComment('<div><span class="x">teks</span> <h1>judul</h1></div>')->html)
            ->toBe('teks judul');
    });

    it('drops the content of script-like elements', function () {
        expect(sanitizeComment('<p>a</p><script>alert(1)</script><style>p{}</style>')->html)
            ->toBe('<p>a</p>');
    });

    it('forces target and rel on links whatever the input says', function () {
        expect(sanitizeComment('<a rel="opener" target="_self" href="https://contoh.test">x</a>')->html)
            ->toBe('<a target="_blank" rel="noopener noreferrer nofollow" href="https://contoh.test">x</a>');
    });

    it('survives deeply nested markup', function () {
        $html = str_repeat('<blockquote>', 2000).'dalam'.str_repeat('</blockquote>', 2000);

        $sanitized = sanitizeComment($html);

        expectOnlyAllowedMarkup($sanitized->html);
        expect($sanitized->text)->toBe('dalam');
    });

    it('replaces invalid UTF-8 with the replacement character', function () {
        expect(sanitizeComment("<p>\xC3\x28</p>"))
            ->html->toBe("<p>\u{FFFD}(</p>")
            ->text->toBe("\u{FFFD}(");
    });
});

describe('images', function () {
    it('keeps an image of the comment\'s own uploads and reports it', function () {
        $sanitized = sanitizeComment('<img src="/attachments/'.ALLOWED_IMAGE.'" alt="Pintu rusak">');

        expect($sanitized)
            ->html->toBe('<img src="/attachments/'.ALLOWED_IMAGE.'" alt="Pintu rusak">')
            ->imageUuids->toBe([ALLOWED_IMAGE]);
    });

    it('names an image without alt text by its file name', function (string $alt) {
        expect(sanitizeComment('<img src="/attachments/'.ALLOWED_IMAGE.'"'.$alt.'>')->html)
            ->toBe('<img src="/attachments/'.ALLOWED_IMAGE.'" alt="foto.jpg">');
    })->with([
        'missing' => '',
        'empty' => ' alt=""',
        'blank' => ' alt="   "',
    ]);

    it('drops an absolute image URL, even one pointing at this app', function () {
        expect(sanitizeComment('<img src="https://worder.test/attachments/'.ALLOWED_IMAGE.'">'))
            ->html->toBe('')
            ->imageUuids->toBe([]);
    });

    it('reports each image once but counts every one shown', function () {
        $image = '<img src="/attachments/'.ALLOWED_IMAGE.'" alt="a">';

        expect(sanitizeComment($image.$image.'<img src="/attachments/'.FOREIGN_IMAGE.'">'))
            ->imageUuids->toBe([ALLOWED_IMAGE])
            ->imageCount->toBe(2);
    });

    it('drops an image the comment may not show and reports nothing', function () {
        expect(sanitizeComment('<p>x</p><img src="/attachments/'.FOREIGN_IMAGE.'">'))
            ->html->toBe('<p>x</p>')
            ->imageUuids->toBe([]);
    });

    it('asks about uppercase uuids in their canonical form', function () {
        $asked = [];
        CommentHtml::sanitize('<img src="/attachments/'.strtoupper(ALLOWED_IMAGE).'">', function (string $uuid) use (&$asked): ?string {
            $asked[] = $uuid;

            return null;
        });

        expect($asked)->toBe([ALLOWED_IMAGE]);
    });
});

describe('plain text', function () {
    it('keeps the words and line structure', function () {
        $text = sanitizeComment(
            '<p>Halo <strong>tim</strong>,</p><p>baris<br>baru</p>'
            .'<ul><li><p>satu</p></li><li><p>dua</p></li></ul>'
            .'<ol><li><p>pertama</p></li></ol>'
            .'<blockquote><p>kutipan</p></blockquote>'
            .'<img src="/attachments/'.ALLOWED_IMAGE.'" alt="foto.jpg"><p>&lt;akhir&gt;</p>',
        )->text;

        expect($text)->toBe("Halo tim,\n\nbaris\nbaru\n\n- satu\n- dua\n\n1. pertama\n\nkutipan\n\n<akhir>");
    });

    it('is empty for a comment with only empty paragraphs or an image', function (string $html) {
        expect(sanitizeComment($html)->text)->toBe('');
    })->with([
        'empty paragraph' => '<p></p>',
        'whitespace' => "<p>  </p><p>\n</p>",
        'image only' => '<img src="/attachments/'.ALLOWED_IMAGE.'">',
    ]);
});

describe('plain text from old comments', function () {
    it('escapes the text and keeps its line breaks', function () {
        expect(CommentHtml::fromPlainText("Baris <b>satu</b>\nbaris & dua\n\n\nParagraf \"baru\""))
            ->toBe('<p>Baris &lt;b&gt;satu&lt;/b&gt;<br>baris &amp; dua</p><p>Paragraf "baru"</p>');
    });

    it('produces what the sanitizer keeps', function () {
        $html = CommentHtml::fromPlainText("<script>alert(1)</script>\r\nx");

        expect(sanitizeComment($html)->html)->toBe($html);
    });
});
