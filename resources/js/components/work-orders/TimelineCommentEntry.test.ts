import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { CommentEntry } from '@/types';
import TimelineCommentEntry from './TimelineCommentEntry.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { displayTimezone: 'Asia/Makassar' } }),
}));

function entry(overrides: Partial<CommentEntry> = {}): CommentEntry {
    return {
        type: 'comment',
        id: 1,
        user: { id: 7, name: 'Dewi Lestari' },
        body: 'Mohon dicek.',
        deleted: false,
        edited: false,
        created_at: '2026-09-25T02:00:00+00:00',
        can: { update: false, delete: false },
        ...overrides,
    };
}

function render(overrides: Partial<CommentEntry> = {}) {
    return mount(TimelineCommentEntry, {
        props: { entry: entry(overrides), workOrderId: 3, maxLength: 2000 },
    });
}

describe('TimelineCommentEntry', () => {
    it('renders markup in the body as text, never as HTML', () => {
        const body = '<script>alert("x")</script><b>tebal</b>';
        const wrapper = render({ body });

        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.get('[data-test="body"]').text()).toBe(body);
    });

    it('keeps line breaks in the text', () => {
        const wrapper = render({ body: 'Baris satu\nBaris dua' });
        const body = wrapper.get('[data-test="body"]');

        expect(body.element.textContent?.trim()).toBe('Baris satu\nBaris dua');
        expect(body.classes()).toContain('whitespace-pre-line');
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
