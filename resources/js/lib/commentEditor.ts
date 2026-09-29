import type { Extensions } from '@tiptap/core';
import Blockquote from '@tiptap/extension-blockquote';
import Bold from '@tiptap/extension-bold';
import Code from '@tiptap/extension-code';
import Document from '@tiptap/extension-document';
import HardBreak from '@tiptap/extension-hard-break';
import Image from '@tiptap/extension-image';
import Italic from '@tiptap/extension-italic';
import Link from '@tiptap/extension-link';
import {
    BulletList,
    ListItem,
    ListKeymap,
    OrderedList,
} from '@tiptap/extension-list';
import Paragraph from '@tiptap/extension-paragraph';
import Strike from '@tiptap/extension-strike';
import Text from '@tiptap/extension-text';
import {
    CharacterCount,
    Dropcursor,
    Gapcursor,
    Placeholder,
    UndoRedo,
} from '@tiptap/extensions';

/**
 * The comment format (FLOW.md §9): paragraphs, line breaks, bold, italic,
 * strike, inline code, lists, blockquotes, links, and images of this
 * comment's uploads. Nothing else: no headings, tables, colours, raw HTML,
 * or embeds. The server sanitizes every body to exactly this
 * (App\Support\Comments\CommentHtml); tests/Fixtures/comment-html.json
 * holds what both sides agree on.
 */

const LINK_PROTOCOLS = ['http:', 'https:', 'mailto:'];

/** The only image source the server keeps: one upload's attachment route. */
const IMAGE_SOURCE =
    /^\/attachments\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Whether a link may be kept: absolute http, https, or mailto.
 */
export function isAllowedLink(url: string): boolean {
    try {
        return LINK_PROTOCOLS.includes(new URL(url.trim()).protocol);
    } catch {
        return false;
    }
}

/**
 * What the user typed in the link field as an allowed link: "contoh.test"
 * becomes https://contoh.test and an email address a mailto link. Null when
 * it cannot be one.
 */
export function normalizeLink(input: string): string | null {
    const value = input.trim();

    if (value === '') {
        return null;
    }

    if (/^[a-z][a-z0-9+.-]*:/i.test(value)) {
        return isAllowedLink(value) ? value : null;
    }

    const candidate = /^[^\s@/]+@[^\s@/]+\.[^\s@/]+$/.test(value)
        ? `mailto:${value}`
        : `https://${value}`;

    return isAllowedLink(candidate) ? candidate : null;
}

/**
 * Images only from this app's attachment route, so a pasted external or
 * data: image never enters the editor (the server would drop it anyway).
 */
const AttachmentImage = Image.extend({
    parseHTML() {
        return [
            {
                tag: 'img[src]',
                getAttrs: (element) =>
                    IMAGE_SOURCE.test(element.getAttribute('src') ?? '')
                        ? null
                        : false,
            },
        ];
    },
}).configure({ inline: false, allowBase64: false });

export function commentExtensions(
    options: { placeholder?: string } = {},
): Extensions {
    return [
        Document,
        Paragraph,
        Text,
        HardBreak,
        Bold,
        Italic,
        Strike,
        Code,
        Blockquote,
        BulletList,
        OrderedList,
        ListItem,
        ListKeymap,
        Link.configure({
            openOnClick: false,
            autolink: true,
            linkOnPaste: true,
            defaultProtocol: 'https',
            isAllowedUri: (url) => isAllowedLink(url),
            shouldAutoLink: (url) => isAllowedLink(normalizeLink(url) ?? ''),
        }),
        AttachmentImage,
        UndoRedo,
        Dropcursor,
        Gapcursor,
        CharacterCount,
        Placeholder.configure({ placeholder: options.placeholder ?? '' }),
    ];
}
