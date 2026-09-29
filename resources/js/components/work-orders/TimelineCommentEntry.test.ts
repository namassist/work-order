import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import type {
    AttachmentRules,
    CommentEntry,
    WorkOrderCommentSettings,
} from '@/types';
import TimelineCommentEntry from './TimelineCommentEntry.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { displayTimezone: 'Asia/Makassar' } }),
}));

const IMAGE = '/attachments/0192f3a4-5b6c-7d8e-9f01-23456789abcd';

const rules: AttachmentRules = {
    name: 'komentar_gambar',
    max_files: 20,
    max_size_kb: 5120,
    accept: '.jpg,.png',
    type_list: 'JPG, PNG',
};

const settings: WorkOrderCommentSettings = {
    max_length: 5000,
    max_images: 10,
    max_documents: 5,
    read_only: false,
    uploads: { gambar: rules, lampiran: rules },
};

function entry(overrides: Partial<CommentEntry> = {}): CommentEntry {
    return {
        type: 'comment',
        id: 1,
        user: { id: 7, name: 'Dewi Lestari' },
        body: '<p>Mohon <strong>dicek</strong>.</p>',
        attachments: [],
        deleted: false,
        edited: false,
        created_at: '2026-09-25T02:00:00+00:00',
        can: { update: false, delete: false },
        ...overrides,
    };
}

function render(overrides: Partial<CommentEntry> = {}) {
    return mount(TimelineCommentEntry, {
        props: { entry: entry(overrides), workOrderId: 3, settings },
        attachTo: document.body,
    });
}

describe('TimelineCommentEntry', () => {
    it('renders the server-sanitized body as HTML', () => {
        const body = render().get('[data-test="body"]');

        expect(body.find('strong').text()).toBe('dicek');
        expect(body.text()).toBe('Mohon dicek.');
    });

    // What the server stores for XSS payloads (tests/Unit/CommentHtmlTest.php)
    // renders inert: escaped markup stays text, links cannot run scripts.
    it('renders stored payload output inert', () => {
        const body = render({
            body:
                '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p><p>"&gt;</p>' +
                '<p><a target="_blank" rel="noopener noreferrer nofollow" href="https://contoh.test">tautan</a></p>',
        }).get('[data-test="body"]');

        expect(body.find('script').exists()).toBe(false);
        expect(body.text()).toContain('<script>alert(1)</script>');
        expect(body.get('a').attributes()).toEqual({
            target: '_blank',
            rel: 'noopener noreferrer nofollow',
            href: 'https://contoh.test',
        });
    });

    it('opens an inline image full size, by click or keyboard', async () => {
        const wrapper = render({
            body: `<p>Foto:</p><img src="${IMAGE}" alt="pintu.jpg">`,
        });
        const image = wrapper.get('[data-test="body"] img');

        expect(image.attributes()).toMatchObject({
            tabindex: '0',
            role: 'button',
            'aria-label': 'Perbesar gambar pintu.jpg',
        });

        await image.trigger('keydown', { key: 'Enter' });

        const dialogImage = document.body.querySelector(
            '[role="dialog"] img',
        ) as HTMLImageElement | null;
        expect(dialogImage?.getAttribute('src')).toBe(IMAGE);
        expect(
            document.body.querySelector('[role="dialog"]')?.textContent,
        ).toContain('pintu.jpg');
        wrapper.unmount();
    });

    it('lists the documents as file rows', () => {
        const wrapper = render({
            attachments: [
                {
                    id: 'b2b1dbfe-8e64-4397-82ad-8cbaff005960',
                    name: 'laporan.pdf',
                    size: 2048,
                    extension: 'pdf',
                    previewable: true,
                    uploader: { id: 7, name: 'Dewi Lestari' },
                    created_at: '2026-09-25T02:00:00+00:00',
                },
            ],
        });
        const rows = wrapper.findAll('[aria-label="Lampiran komentar"] li');

        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('laporan.pdf');
        expect(
            rows[0].get('a[aria-label="Unduh laporan.pdf"]').attributes('href'),
        ).toBe('/attachments/b2b1dbfe-8e64-4397-82ad-8cbaff005960?download=1');
    });

    it('shows the author and the time in WITA', () => {
        const text = render().text();

        expect(text).toContain('Dewi Lestari');
        expect(text).toContain('25 Sep 2026 10:00');
    });

    it('marks an edited comment', () => {
        expect(
            render({ edited: true }).find('[data-test="edited"]').text(),
        ).toBe('(diedit)');
        expect(render().find('[data-test="edited"]').exists()).toBe(false);
    });

    it('shows a deleted comment as a placeholder', () => {
        const wrapper = render({ deleted: true, body: null, edited: true });

        expect(wrapper.get('[data-test="deleted"]').text()).toBe(
            'Komentar dihapus',
        );
        expect(wrapper.find('[data-test="body"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="edited"]').exists()).toBe(false);
    });

    it('offers the actions menu only when the comment may be changed', () => {
        expect(render().find('button').exists()).toBe(false);
        expect(
            render({ can: { update: true, delete: true } })
                .find('button[aria-label="Aksi komentar Dewi Lestari"]')
                .exists(),
        ).toBe(true);
    });
});
