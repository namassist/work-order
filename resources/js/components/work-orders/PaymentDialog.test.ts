import { flushPromises, mount } from '@vue/test-utils';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import type { WorkOrderInvoice } from '@/types';
import PaymentDialog from './PaymentDialog.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { displayTimezone: 'Asia/Makassar' } }),
}));

const invoice: WorkOrderInvoice = {
    number: 'INV/ENG/2026/001',
    invoice_date: '2026-09-20',
    amount: null,
    due_date: null,
    paid_on: null,
    is_overdue: false,
    issued_by: { name: 'Bambang Hartono' },
    corrected_by: null,
    paid_by: null,
};

beforeEach(() => {
    // 01:00 WITA on 27 Sep, still 26 Sep in UTC.
    vi.useFakeTimers({
        now: new Date('2026-09-26T17:00:00Z'),
        toFake: ['Date'],
    });
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('PaymentDialog', () => {
    it('asks for a payment date between the invoice date and today in WITA', async () => {
        const wrapper = mount(PaymentDialog, {
            props: {
                open: false,
                workOrderId: 3,
                displayNumber: 'WO/PRD/2026/09/0001',
                invoice,
                rules: {
                    name: 'bukti_bayar',
                    max_files: 5,
                    max_size_kb: 10240,
                    accept: '.pdf',
                    type_list: 'PDF',
                },
            },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        const date = document.querySelector<HTMLInputElement>('#payment-date');

        expect(date?.value).toBe('2026-09-27');
        expect(date?.min).toBe('2026-09-20');
        expect(date?.max).toBe('2026-09-27');
        expect(date?.required).toBe(true);
        expect(document.body.textContent).toContain('INV/ENG/2026/001');
    });
});
