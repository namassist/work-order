import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vite-plus/test';
import type { WorkOrderTransition } from '@/types';
import TransitionDialog from './TransitionDialog.vue';

const reject: WorkOrderTransition = {
    value: 'ditolak',
    label: 'Tolak',
    destructive: true,
    requires_note: true,
    note_label: 'Alasan penolakan',
    blocked_reason: null,
};

const accept: WorkOrderTransition = {
    value: 'pelaksanaan',
    label: 'Setujui',
    destructive: false,
    requires_note: false,
    note_label: 'Catatan',
    blocked_reason: null,
};

async function render(transition: WorkOrderTransition) {
    const wrapper = mount(TransitionDialog, {
        props: {
            open: true,
            workOrderId: 3,
            displayNumber: 'WO/PRD/2026/09/0001',
            transition,
        },
        attachTo: document.body,
    });
    // The dialog content is teleported to the body once it opens.
    await flushPromises();

    return wrapper;
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('TransitionDialog', () => {
    it('asks for the reason to reject, required, with a destructive button', async () => {
        await render(reject);
        const note =
            document.querySelector<HTMLTextAreaElement>('#transition-note');
        const label = document.querySelector('label[for="transition-note"]');
        const submit = document.querySelector('button[type="submit"]');

        expect(label?.textContent).toContain('Alasan penolakan');
        expect(label?.textContent).not.toContain('opsional');
        expect(note?.required).toBe(true);
        expect(submit?.textContent?.trim()).toBe('Tolak');
        expect(submit?.getAttribute('data-variant')).toBe('destructive');
    });

    it('lets the target department accept with an optional note', async () => {
        await render(accept);
        const label = document.querySelector('label[for="transition-note"]');
        const submit = document.querySelector('button[type="submit"]');

        expect(label?.textContent).toContain('Catatan');
        expect(label?.textContent).toContain('(opsional)');
        expect(
            document.querySelector<HTMLTextAreaElement>('#transition-note')
                ?.required,
        ).toBe(false);
        expect(submit?.getAttribute('data-variant')).toBe('default');
    });
});
