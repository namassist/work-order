<?php

/**
 * XSS and mutation-XSS payloads every HTML sanitizer must neutralize: the
 * comment sanitizer (CommentHtmlTest) and the BAST template sanitizer
 * (BastTemplateHtmlTest) run the same list.
 *
 * @param  string  $allowedImage  the uuid of an upload the sanitized HTML may show
 * @param  string  $foreignImage  the uuid of an upload it may not show
 * @return array<string, string>
 */
function xssPayloads(string $allowedImage, string $foreignImage): array
{
    return [
        'script tag' => '<script>alert(1)</script>',
        'script inside a paragraph' => '<p>Hai<script>alert(1)</script></p>',
        'split script tag' => '<scr<script>ipt>alert(1)</script>',
        'nested angle brackets' => '<<script>script>alert(1)<</script>/script>',
        'img onerror' => '<img src=x onerror=alert(1)>',
        'onerror on an allowed image' => '<img src="/attachments/'.$allowedImage.'" onerror="alert(1)">',
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
        'another work order\'s image' => '<img src="/attachments/'.$foreignImage.'">',
        'path traversal after an allowed uuid' => '<img src="/attachments/'.$allowedImage.'/../../x">',
        'allowed uuid with a query' => '<img src="/attachments/'.$allowedImage.'?download=1">',
        'srcset' => '<img src="/attachments/'.$allowedImage.'" srcset="https://evil.test/x.png 2x">',
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
    ];
}
