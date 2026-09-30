import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import type {
    WorkOrderDailyReport,
    WorkOrderDailyReportDay,
    WorkOrderDailyReportSettings,
} from '@/types';
import DailyReportSection from './DailyReportSection.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { displayTimezone: 'Asia/Makassar' } }),
}));

const settings: WorkOrderDailyReportSettings = {
    today: '2026-09-29',
    earliest_date: '2026-09-25',
    note_max_length: 1000,
    max_links: 5,
    link_max_length: 2048,
    link_domains: [],
    files: {
        name: 'berkas',
        max_files: 5,
        max_size_kb: 10240,
        accept: '.xlsx,.pdf',
        type_list: 'XLSX, PDF',
    },
};

const report = (
    overrides: Partial<WorkOrderDailyReport> = {},
): WorkOrderDailyReport => ({
    id: 1,
    report_date: '2026-09-28',
    note: 'Panel lantai 2 selesai 60%.',
    links: ['https://unggul.sharepoint.com/sites/wo/Timesheet_20260928.xlsx'],
    files: [],
    reporter: { name: 'Fajar Nugroho' },
    editor: null,
    created_at: '2026-09-28T07:30:00+00:00',
    updated_at: '2026-09-28T07:30:00+00:00',
    can_edit: false,
    ...overrides,
});

const days: WorkOrderDailyReportDay[] = [
    { date: '2026-09-28', state: 'reported' },
    { date: '2026-09-29', state: 'missing' },
];

function render(
    props: {
        reports?: WorkOrderDailyReport[];
        missing?: boolean;
        canReport?: boolean;
    } = {},
) {
    return mount(DailyReportSection, {
        props: {
            workOrderId: 3,
            reports: [report()],
            days,
            settings,
            missing: false,
            canReport: false,
            ...props,
        },
        attachTo: document.body,
    });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('DailyReportSection', () => {
    it('opens links in a new tab without referrer or opener, labelled by host and file', () => {
        const link = render().get('a');

        expect(link.attributes('href')).toBe(
            'https://unggul.sharepoint.com/sites/wo/Timesheet_20260928.xlsx',
        );
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener noreferrer nofollow');
        expect(link.text()).toBe(
            'unggul.sharepoint.com/sites/wo/Timesheet_20260928.xlsx',
        );
    });

    it('never turns an unsafe stored link into an href', () => {
        const wrapper = render({
            reports: [report({ links: ['javascript:alert(1)'] })],
        });

        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.text()).toContain('javascript:alert(1)');
    });

    it('labels each day of the strip for screen readers', () => {
        const labels = render()
            .findAll('ol li .sr-only')
            .map((label) => label.text().replace(/\s+/g, ' '));

        expect(labels).toEqual([
            '28 Sep 2026: Sudah lapor',
            '29 Sep 2026: Belum lapor',
        ]);
    });

    it('flags a missing report and offers posting only to reporters', () => {
        const viewer = render({ missing: true });

        expect(viewer.find('[data-test="daily-report-missing"]').text()).toBe(
            'Belum lapor hari ini.',
        );
        expect(viewer.text()).not.toContain('Tambah laporan');
        expect(render({ canReport: true }).text()).toContain('Tambah laporan');
    });

    it('shows who edited a report and offers editing only within its window', () => {
        const wrapper = render({
            reports: [
                report({ editor: { name: 'Hendra Gunawan' }, can_edit: true }),
                report({ id: 2, report_date: '2026-09-25', can_edit: false }),
            ],
        });

        expect(wrapper.text()).toContain('diubah oleh Hendra Gunawan');
        expect(
            wrapper.findAll('button[aria-label^="Ubah laporan"]'),
        ).toHaveLength(1);
    });

    it('says so when there is no report yet', async () => {
        const wrapper = render({ reports: [] });
        await flushPromises();

        expect(wrapper.text()).toContain('Belum ada laporan harian.');
    });
});
