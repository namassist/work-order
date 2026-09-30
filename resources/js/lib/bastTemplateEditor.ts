import type { Extensions } from '@tiptap/core';
import Bold from '@tiptap/extension-bold';
import Document from '@tiptap/extension-document';
import HardBreak from '@tiptap/extension-hard-break';
import Heading from '@tiptap/extension-heading';
import HorizontalRule from '@tiptap/extension-horizontal-rule';
import Image from '@tiptap/extension-image';
import Italic from '@tiptap/extension-italic';
import {
    BulletList,
    ListItem,
    ListKeymap,
    OrderedList,
} from '@tiptap/extension-list';
import Paragraph from '@tiptap/extension-paragraph';
import Strike from '@tiptap/extension-strike';
import {
    Table,
    TableCell,
    TableHeader,
    TableRow,
} from '@tiptap/extension-table';
import Text from '@tiptap/extension-text';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import { Dropcursor, Gapcursor, UndoRedo } from '@tiptap/extensions';

/**
 * The BAST template format (FLOW.md §9): paragraphs, line breaks, headings
 * (h1–h3), bold, italic, underline, strike, lists, horizontal rules,
 * tables, text alignment, and images of the template's uploads. No links,
 * colours, or raw HTML. Placeholders ({{nomor_bast}}) are plain text. The
 * server sanitizes every save to exactly this
 * (App\Support\Bast\BastTemplateHtml); tests/Fixtures/bast-template-html.json
 * holds what both sides agree on.
 */

/** The only image source the server keeps: one upload's attachment route. */
const IMAGE_SOURCE =
    /^\/attachments\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export const ALIGNMENTS = ['left', 'center', 'right', 'justify'] as const;

export type Alignment = (typeof ALIGNMENTS)[number];

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

/**
 * A table written as the server keeps it: no column widths, no styles.
 */
const PlainTable = Table.extend({
    renderHTML() {
        return ['table', ['tbody', 0]];
    },
}).configure({ resizable: false });

/** Cells keep colspan and rowspan only; widths and cell alignment are not written. */
const cellAttributes = {
    colspan: { default: 1 },
    rowspan: { default: 1 },
    colwidth: { default: null, rendered: false },
};

const PlainTableCell = TableCell.extend({
    addAttributes: () => cellAttributes,
});

const PlainTableHeader = TableHeader.extend({
    addAttributes: () => cellAttributes,
});

export function bastTemplateExtensions(): Extensions {
    return [
        Document,
        Paragraph,
        Text,
        HardBreak,
        Heading.configure({ levels: [1, 2, 3] }),
        Bold,
        Italic,
        Underline,
        Strike,
        BulletList,
        OrderedList,
        ListItem,
        ListKeymap,
        HorizontalRule,
        TextAlign.configure({
            types: ['heading', 'paragraph'],
            alignments: [...ALIGNMENTS],
        }),
        PlainTable,
        TableRow,
        PlainTableHeader,
        PlainTableCell,
        AttachmentImage,
        UndoRedo,
        Dropcursor,
        Gapcursor,
    ];
}

/** The attachment route of a template image, as the server keeps it. */
export function templateImageSource(uuid: string): string {
    return `/attachments/${uuid}`;
}
