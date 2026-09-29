import { Editor } from '@tiptap/core';
import { afterEach, describe, expect, it } from 'vite-plus/test';
import fixtures from '../../../tests/Fixtures/comment-html.json';
import {
    commentExtensions,
    isAllowedLink,
    normalizeLink,
} from './commentEditor';

const IMAGE_SRC = `/attachments/${fixtures.imageUuid}`;

let editor: Editor | null = null;

function editorWith(html: string): Editor {
    editor = new Editor({ extensions: commentExtensions(), content: html });

    return editor;
}

afterEach(() => {
    editor?.destroy();
    editor = null;
});

describe('comment editor output', () => {
    // The server keeps these unchanged (tests/Unit/CommentHtmlTest.php), so
    // what the editor writes is stored byte for byte and reloads the same.
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

        expect(format('<p>tebal</p>', (e) => e.commands.toggleBold())).toBe(
            '<p><strong>tebal</strong></p>',
        );
        expect(format('<p>miring</p>', (e) => e.commands.toggleItalic())).toBe(
            '<p><em>miring</em></p>',
        );
        expect(format('<p>coret</p>', (e) => e.commands.toggleStrike())).toBe(
            '<p><s>coret</s></p>',
        );
        expect(format('<p>kode()</p>', (e) => e.commands.toggleCode())).toBe(
            '<p><code>kode()</code></p>',
        );
        expect(
            format('<p>satu</p>', (e) => e.commands.toggleBulletList()),
        ).toBe('<ul><li><p>satu</p></li></ul>');
        expect(
            format('<p>pertama</p>', (e) => e.commands.toggleOrderedList()),
        ).toBe('<ol><li><p>pertama</p></li></ol>');
        expect(
            format('<p>kutipan</p>', (e) => e.commands.toggleBlockquote()),
        ).toBe(fixtures.cases.blockquote);
        expect(
            format('<p>admin</p>', (e) =>
                e.commands.setLink({ href: 'mailto:admin@worder.test' }),
            ),
        ).toBe(fixtures.cases['mailto link']);
    });

    it('inserts an uploaded image in the stored form', () => {
        const e = editorWith('<p>Foto:</p>');
        e.commands.focus('end');
        e.commands.setImage({ src: IMAGE_SRC, alt: 'foto.jpg' });

        expect(e.getHTML()).toContain(
            `<img src="${IMAGE_SRC}" alt="foto.jpg">`,
        );
    });

    it('writes a hard break as <br>', () => {
        const e = editorWith('<p>baris satu</p>');
        e.commands.focus('end');
        e.commands.setHardBreak();
        e.commands.insertContent('baris dua');

        expect(e.getHTML()).toBe(fixtures.cases['hard break']);
    });

    it('drops what the comment format does not have', () => {
        const html = editorWith(
            '<h1>Judul</h1><table><tr><td>sel</td></tr></table>' +
                '<p style="color:red" onclick="alert(1)"><u>garis</u> <span class="x">teks</span></p>' +
                '<img src="https://evil.test/x.png"><img src="data:image/png;base64,AAAA">' +
                '<p><a href="javascript:alert(1)">klik</a></p><script>alert(2)</script>' +
                '<pre><code>blok</code></pre><hr>',
        ).getHTML();

        expect(html).toBe(
            // A pasted code block keeps its text as inline code.
            '<p>Judul</p><p>sel</p><p>garis teks</p><p>klik</p><p><code>blok</code></p>',
        );
    });
});

describe('links', () => {
    it.each([
        ['https://contoh.test', true],
        ['http://contoh.test/a?b=1', true],
        ['mailto:admin@worder.test', true],
        ['javascript:alert(1)', false],
        ['JavaScript:alert(1)', false],
        ['data:text/html,x', false],
        ['/relatif', false],
        ['ftp://contoh.test', false],
    ])('allows %s: %s', (url, allowed) => {
        expect(isAllowedLink(url)).toBe(allowed);
    });

    it.each([
        ['contoh.test', 'https://contoh.test'],
        [' https://contoh.test/a ', 'https://contoh.test/a'],
        ['admin@worder.test', 'mailto:admin@worder.test'],
        ['javascript:alert(1)', null],
        ['', null],
    ])('normalizes %s to %s', (input, expected) => {
        expect(normalizeLink(input)).toBe(expected);
    });
});
