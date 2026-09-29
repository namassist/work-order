import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { AttachmentRules, WorkOrderCommentSettings } from '@/types';
import CommentEditor from './CommentEditor.vue';

// Uploads never finish, so a file stays in flight while the next is picked.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    useHttp: () => ({
        file: null,
        errors: {},
        post: () => new Promise(() => {}),
        cancel: () => {},
    }),
}));

const rules = (accept: string): AttachmentRules => ({
    name: 'komentar',
    max_files: 20,
    max_size_kb: 1,
    accept,
    type_list: accept.toUpperCase(),
});

const settings: WorkOrderCommentSettings = {
    max_length: 10,
    max_images: 10,
    max_documents: 0,
    read_only: false,
    uploads: { gambar: rules('.jpg'), lampiran: rules('.pdf') },
};

async function render(html = '') {
    const wrapper = mount(CommentEditor, {
        props: {
            modelValue: html,
            'onUpdate:modelValue': (value: string) =>
                wrapper.setProps({ modelValue: value }),
            workOrderId: 3,
            settings,
            inputId: 'komentar',
            label: 'Komentar',
        },
        attachTo: document.body,
    });
    await flushPromises();

    return wrapper;
}

const pick = async (
    wrapper: Awaited<ReturnType<typeof render>>,
    index: number,
    file: File,
) => {
    const input = wrapper.findAll('input[type="file"]')[index];
    Object.defineProperty(input.element, 'files', {
        value: [file],
        configurable: true,
    });
    await input.trigger('change');
};

describe('CommentEditor', () => {
    it('labels the editable area and every toolbar button', async () => {
        const wrapper = await render();

        expect(wrapper.get('#komentar').attributes()).toMatchObject({
            role: 'textbox',
            'aria-label': 'Komentar',
            'aria-multiline': 'true',
            contenteditable: 'true',
        });
        expect(
            wrapper
                .findAll('[role="toolbar"] button')
                .map((button) => button.attributes('aria-label')),
        ).toEqual([
            'Tebal (Ctrl+B)',
            'Miring (Ctrl+I)',
            'Coret (Ctrl+Shift+S)',
            'Kode (Ctrl+E)',
            'Daftar berpoin (Ctrl+Shift+8)',
            'Daftar bernomor (Ctrl+Shift+7)',
            'Kutipan (Ctrl+Shift+B)',
            'Tautan (Ctrl+K)',
            'Sisipkan gambar',
            'Lampirkan dokumen',
        ]);
        wrapper.unmount();
    });

    it('shows which formats apply at the cursor', async () => {
        const wrapper = await render('<p><strong>tebal</strong></p>');

        expect(
            wrapper
                .get('button[aria-label="Tebal (Ctrl+B)"]')
                .attributes('aria-pressed'),
        ).toBe('true');
        expect(
            wrapper
                .get('button[aria-label="Miring (Ctrl+I)"]')
                .attributes('aria-pressed'),
        ).toBe('false');
        wrapper.unmount();
    });

    it('formats from the toolbar and reports the HTML', async () => {
        const wrapper = await render('<p>satu</p>');

        await wrapper
            .get('button[aria-label="Daftar berpoin (Ctrl+Shift+8)"]')
            .trigger('click');

        expect(wrapper.props('modelValue')).toBe(
            '<ul><li><p>satu</p></li></ul>',
        );
        wrapper.unmount();
    });

    it('empties when the form resets the model', async () => {
        const wrapper = await render('<p>terkirim</p>');

        await wrapper.setProps({ modelValue: '' });

        expect(wrapper.get('#komentar').text()).toBe('');
        wrapper.unmount();
    });

    it('counts characters against the limit', async () => {
        const wrapper = await render('<p>sebelas huruf</p>');

        expect(wrapper.text()).toContain('13/10');
        wrapper.unmount();
    });

    it('refuses files the comment cannot take before uploading', async () => {
        const wrapper = await render();

        await pick(wrapper, 0, new File(['x'], 'skrip.svg'));
        expect(wrapper.text()).toContain(
            'skrip.svg: jenis berkas tidak diizinkan. Gunakan .JPG.',
        );

        await pick(wrapper, 1, new File(['x'], 'laporan.pdf'));
        expect(wrapper.text()).toContain(
            'laporan.pdf: maksimal 0 lampiran per komentar.',
        );
        wrapper.unmount();
    });

    it('counts the file being uploaded against the per-comment limit', async () => {
        const wrapper = mount(CommentEditor, {
            props: {
                modelValue: '',
                workOrderId: 3,
                settings: { ...settings, max_images: 1 },
                inputId: 'komentar',
                label: 'Komentar',
            },
            attachTo: document.body,
        });
        await flushPromises();

        await pick(wrapper, 0, new File(['x'], 'pertama.jpg'));
        expect(wrapper.text()).toContain('Mengunggah pertama.jpg');

        await pick(wrapper, 0, new File(['x'], 'kedua.jpg'));
        expect(wrapper.text()).toContain(
            'kedua.jpg: maksimal 1 gambar per komentar.',
        );
        wrapper.unmount();
    });
});
