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
    })->with([
        'script tag' => '<script>alert(1)</script>',
        'script inside a paragraph' => '<p>Hai<script>alert(1)</script></p>',
        'split script tag' => '<scr<script>ipt>alert(1)</script>',
        'nested angle brackets' => '<<script>script>alert(1)<</script>/script>',
        'img onerror' => '<img src=x onerror=alert(1)>',
        'onerror on an allowed image' => '<img src="/attachments/'.ALLOWED_IMAGE.'" onerror="alert(1)">',
        'onload on body' => '<body onload=alert(1)><p>x</p></body>',
        'onclick on a paragraph' => '<p onclick="alert(1)">x</p>',
        'onmouseover on a link' => '<a href="https://contoh.test" onmouseover="alert(1)">x</a>',
        'autofocus onfocus' => '<input autofocus onfocus=alert(1)>',
        'details ontoggle' => '<details open ontoggle=alert(1)><summary>x</summary></details>',
        'video source onerror' => '<video><source onerror=alert(1)></video>',
        'javascript link' => '<a href="javascript:alert(1)">x</a>',
        'mixed-case javascript link' => '<a href="JaVaScRiPt:alert(1)">x</a>',
        'javascript link with a tab entity' => '<a href="java&#x09;script:alert(1)">x</a>',
        'javascript link with leading space' => '<a href=" javascript:alert(1)">x</a>',
        'javascript link with encoded colon' => '<a href="javascript&colon;alert(1)">x</a>',
        'data link' => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
        'vbscript link' => '<a href="vbscript:msgbox(1)">x</a>',
        'style attribute' => '<p style="background:url(javascript:alert(1))">x</p>',
        'style element' => '<style>@import "https://evil.test/x.css";</style><p>x</p>',
        'iframe' => '<iframe src="https://evil.test"></iframe>',
        'iframe srcdoc' => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
        'object' => '<object data="https://evil.test/x.swf"></object>',
        'embed' => '<embed src="https://evil.test/x.swf">',
        'svg onload' => '<svg onload=alert(1)></svg>',
        'svg script' => '<svg><script>alert(1)</script></svg>',
        'svg link' => '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
        'math' => '<math><maction actiontype="statusline" xlink:href="javascript:alert(1)">x</maction></math>',
        'external image' => '<img src="https://evil.test/x.png">',
        'protocol-relative image' => '<img src="//evil.test/x.png">',
        'data image' => '<img src="data:image/png;base64,iVBORw0KGgo=">',
        'svg data image' => '<img src="data:image/svg+xml,<svg onload=alert(1)>">',
        'another work order\'s image' => '<img src="/attachments/'.FOREIGN_IMAGE.'">',
        'path traversal after an allowed uuid' => '<img src="/attachments/'.ALLOWED_IMAGE.'/../../x">',
        'allowed uuid with a query' => '<img src="/attachments/'.ALLOWED_IMAGE.'?download=1">',
        'srcset' => '<img src="/attachments/'.ALLOWED_IMAGE.'" srcset="https://evil.test/x.png 2x">',
        'base' => '<base href="https://evil.test/"><p>x</p>',
        'meta refresh' => '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
        'form action' => '<form action="javascript:alert(1)"><button>x</button></form>',
        'template' => '<template><img src=x onerror=alert(1)></template>',
        'html comment' => '<!--<img src=x onerror=alert(1)>--><p>x</p>',
        'textarea' => '<textarea><img src=x onerror=alert(1)></textarea>',
        'mxss noscript' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>',
        'mxss math style' => '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)></style></mglyph></table></mtext></math>',
        'mxss form and math' => '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
        'mxss svg style' => '<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">',
        'xmp' => '<xmp><img src=x onerror=alert(1)></xmp>',
        'escaped script stays text' => '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
        'malformed nesting' => '<p>awal <strong>tebal <em>keduanya</p> sisa</strong></em>',
        'unclosed tags' => '<ul><li>satu<li>dua<blockquote>kutip',
    ]);

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
