import { Editor } from '@tiptap/core';
import { afterEach, describe, expect, it } from 'vite-plus/test';
import fixtures from '../../../tests/Fixtures/bast-template-html.json';
import {
    bastTemplateExtensions,
    templateImageSource,
} from './bastTemplateEditor';

let editor: Editor | null = null;

function editorWith(html: string): Editor {
    editor = new Editor({
        extensions: bastTemplateExtensions(),
        content: html,
    });

    return editor;
}

afterEach(() => {
    editor?.destroy();
    editor = null;
});

describe('BAST template editor output', () => {
    // The server keeps these unchanged (BastTemplateHtmlTest), so what the
    // editor writes is stored byte for byte and reloads the same.
    it.each(Object.entries(fixtures.cases))(
        'reloads %s unchanged',
        (_name, html) => {
            expect(editorWith(html).getHTML()).toBe(html);
        },
    );

    it('writes each toolbar format in the stored form', () => {
        const format = (html: string, apply: (e: Editor) => void): string => {
            const e = editorWith(html);
            e.commands.selectAll();
            apply(e);

            return e.getHTML();
        };

        expect(format('<p>a</p>', (e) => e.commands.toggleUnderline())).toBe(
            '<p><u>a</u></p>',
        );
        expect(
            format('<p>a</p>', (e) => e.commands.toggleHeading({ level: 2 })),
        ).toBe('<h2>a</h2>');
        expect(
            format('<p>a</p>', (e) => e.commands.setTextAlign('center')),
        ).toBe('<p style="text-align: center;">a</p>');
    });

    it('inserts a table without widths or styles', () => {
        const e = editorWith('<p></p>');
        e.commands.insertTable({ rows: 2, cols: 2, withHeaderRow: true });

        expect(e.getHTML()).toBe(
            '<table><tbody><tr><th colspan="1" rowspan="1"><p></p></th><th colspan="1" rowspan="1"><p></p></th></tr><tr><td colspan="1" rowspan="1"><p></p></td><td colspan="1" rowspan="1"><p></p></td></tr></tbody></table>',
        );
    });

    it('drops links, colours, and images from elsewhere', () => {
        expect(
            editorWith(
                '<p><a href="https://contoh.test">tautan</a> <span style="color: red">merah</span></p><img src="https://evil.test/x.png"><img src="data:image/png;base64,AAAA">',
            ).getHTML(),
        ).toBe('<p>tautan merah</p>');
    });

    it('keeps images of the template uploads', () => {
        const src = templateImageSource(fixtures.imageUuid);

        expect(editorWith(`<img src="${src}" alt="kop.png">`).getHTML()).toBe(
            `<img src="${src}" alt="kop.png">`,
        );
    });
});
