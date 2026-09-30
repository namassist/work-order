import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import type {
    WorkOrderDailyReport,
    WorkOrderDailyReportSettings,
} from '@/types';
import DailyReportDialog from './DailyReportDialog.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { displayTimezone: 'Asia/Makassar' } }),
}));

const settings: WorkOrderDailyReportSettings = {
    today: '2026-09-29',
    earliest_date: '2026-09-25',
    note_max_length: 1000,
    max_links: 2,
    link_max_length: 2048,
    link_domains: ['sharepoint.com'],
    files: {
        name: 'berkas',
        max_files: 5,
        max_size_kb: 10240,
        accept: '.xlsx,.pdf',
        type_list: 'XLSX, PDF',
    },
};

const fileId = '6b1f9a52-0000-4000-8000-000000000001';

const existing: WorkOrderDailyReport = {
    id: 7,
    report_date: '2026-09-28',
    note: 'Panel lantai 2 selesai 60%.',
    links: [],
    files: [
        {
            id: fileId,
            name: 'Timesheet.xlsx',
            size: 4600,
            extension: 'xlsx',
            previewable: false,
            uploader: { id: 5, name: 'Fajar Nugroho' },
            created_at: '2026-09-28T07:30:00+00:00',
        },
    ],
    reporter: { name: 'Fajar Nugroho' },
    editor: null,
    created_at: '2026-09-28T07:30:00+00:00',
    updated_at: '2026-09-28T07:30:00+00:00',
    can_edit: true,
};

async function render(report: WorkOrderDailyReport | null) {
    const wrapper = mount(DailyReportDialog, {
        props: { open: false, workOrderId: 3, report, settings },
        attachTo: document.body,
    });
    // Opening fills the form; the content is teleported to the body.
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

const byId = <T extends HTMLElement>(id: string) =>
    document.querySelector<T>(`#${id}`);
const submit = () =>
    document.querySelector<HTMLButtonElement>('button[type="submit"]');

afterEach(() => {
    document.body.innerHTML = '';
});

describe('DailyReportDialog', () => {
    it('dates a new report today, within the back-dating window', async () => {
        await render(null);

        const date = byId<HTMLInputElement>('report-date');
        expect(date?.value).toBe('2026-09-29');
        expect(date?.min).toBe('2026-09-25');
        expect(date?.max).toBe('2026-09-29');
        expect(document.body.textContent).toContain('sharepoint.com');
    });

    it('needs a file or a link before it can be saved', async () => {
        await render(null);

        expect(submit()?.disabled).toBe(true);

        const link = byId<HTMLInputElement>('report-link-0');
        link!.value = 'https://unggul.sharepoint.com/x.xlsx';
        link!.dispatchEvent(new Event('input'));
        await flushPromises();

        expect(submit()?.disabled).toBe(false);
    });

    it('limits the number of links', async () => {
        await render(null);

        const add = [...document.querySelectorAll('button')].find((button) =>
            button.textContent?.includes('Tambah tautan'),
        );
        add?.click();
        await flushPromises();

        expect(document.querySelectorAll('[id^="report-link-"]')).toHaveLength(
            2,
        );
        expect(add?.disabled).toBe(true);
    });

    it('keeps the date of an edited report and offers removing its files', async () => {
        await render(existing);

        expect(byId('report-date')).toBeNull();
        expect(document.body.textContent).toContain('Ubah laporan 28 Sep 2026');
        expect(document.body.textContent).toContain('Hapus Timesheet.xlsx');
        expect(submit()?.disabled).toBe(false);

        byId<HTMLButtonElement>(`remove-report-file-${fileId}`)?.click();
        await flushPromises();

        // Removing its only file leaves nothing to show for the day.
        expect(submit()?.disabled).toBe(true);
    });
});
