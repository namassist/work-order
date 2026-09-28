import { flushPromises, mount } from '@vue/test-utils';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import type { Attachment, AttachmentRules, WorkOrderInvoice } from '@/types';
import InvoiceDialog from './InvoiceDialog.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { displayTimezone: 'Asia/Makassar' } }),
}));

const rules = (name: string): AttachmentRules => ({
    name,
    max_files: 5,
    max_size_kb: 10240,
    accept: '.pdf,application/pdf',
    type_list: 'PDF',
});

const invoice: WorkOrderInvoice = {
    number: 'INV/ENG/2026/001',
    invoice_date: '2026-09-20',
    amount: '1500000.00',
    due_date: '2026-10-20',
    paid_on: null,
    is_overdue: false,
    issued_by: { name: 'Bambang Hartono' },
    corrected_by: null,
    paid_by: null,
};

const file: Attachment = {
    id: '6b1f9a52-0000-4000-8000-000000000001',
    name: 'Invoice 001.pdf',
    size: 1600,
    extension: 'pdf',
    previewable: true,
    uploader: { id: 5, name: 'Bambang Hartono' },
    created_at: '2026-09-20T02:00:00+00:00',
};

async function render(props: { invoice: WorkOrderInvoice | null }) {
    const wrapper = mount(InvoiceDialog, {
        props: {
            open: false,
            workOrderId: 3,
            displayNumber: 'WO/PRD/2026/09/0001',
            rules: { invoice: rules('invoice'), bast: rules('bast') },
            files: { invoice: [file], bast: [] },
            ...props,
        },
        attachTo: document.body,
    });
    // Opening fills the form; the content is teleported to the body.
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

const input = (id: string) =>
    document.querySelector<HTMLInputElement>(`#${id}`);
const submit = () =>
    document.querySelector<HTMLButtonElement>('button[type="submit"]');

beforeEach(() => {
    // 07:30 WITA on 26 Sep, still 25 Sep in UTC.
    vi.useFakeTimers({
        now: new Date('2026-09-25T23:30:00Z'),
        toFake: ['Date'],
    });
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('InvoiceDialog', () => {
    it('issues an invoice dated today in WITA, and needs an invoice file first', async () => {
        await render({ invoice: null });

        expect(input('invoice-date')?.value).toBe('2026-09-26');
        expect(input('invoice-date')?.max).toBe('2026-09-26');
        expect(input('invoice-number')?.required).toBe(true);
        expect(submit()?.textContent?.trim()).toBe('Tagihkan');
        expect(submit()?.disabled).toBe(true);
        expect(document.body.textContent).not.toContain('Berkas saat ini');
    });

    it('shows the amount as rupiah while it is typed', async () => {
        await render({ invoice: null });
        const amount = input('invoice-amount');

        amount!.value = '1.500.000,5';
        amount!.dispatchEvent(new Event('input'));
        await flushPromises();

        expect(
            document.querySelector('#invoice-amount-hint')?.textContent?.trim(),
        ).toBe('Rp 1.500.000,50');

        amount!.value = '1,5,0';
        amount!.dispatchEvent(new Event('input'));
        await flushPromises();

        expect(
            document.querySelector('#invoice-amount-hint')?.textContent?.trim(),
        ).toBe('Bukan jumlah rupiah yang valid.');
    });

    it('corrects an invoice, starting from its values, and offers its files for removal', async () => {
        await render({ invoice });

        expect(input('invoice-number')?.value).toBe('INV/ENG/2026/001');
        expect(input('invoice-date')?.value).toBe('2026-09-20');
        expect(input('invoice-amount')?.value).toBe('1.500.000');
        expect(input('invoice-due-date')?.value).toBe('2026-10-20');
        expect(
            document.querySelector(`label[for="remove-${file.id}"]`)
                ?.textContent,
        ).toContain('Hapus Invoice 001.pdf');
        expect(submit()?.textContent?.trim()).toBe('Simpan koreksi');
        expect(submit()?.disabled).toBe(false);
    });
});
