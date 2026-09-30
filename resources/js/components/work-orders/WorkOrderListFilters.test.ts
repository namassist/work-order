import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import FilterSelect from '@/components/list-filters/FilterSelect.vue';
import type { WorkOrderFilters } from '@/lib/workOrderFilters';
import { EMPTY_WORK_ORDER_FILTERS } from '@/lib/workOrderFilters';
import WorkOrderListFilters from './WorkOrderListFilters.vue';

const routerGet = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { displayTimezone: 'Asia/Makassar' } }),
    router: { get: routerGet },
}));

const options = {
    statuses: [
        { value: 'draft', label: 'Draft', tone: 'secondary' },
        { value: 'diajukan', label: 'Diajukan', tone: 'warning' },
    ],
    statusGroups: [{ value: 'aktif', label: 'Aktif' }],
    urgencies: [
        { value: 'normal', label: 'Normal' },
        { value: 'mendesak', label: 'Mendesak' },
    ],
    paymentStatuses: [
        {
            value: 'belum_ditagih' as const,
            label: 'Belum ditagih',
            tone: 'secondary',
        },
        { value: 'ditagih' as const, label: 'Ditagih', tone: 'warning' },
        { value: 'lunas' as const, label: 'Lunas', tone: 'success' },
    ],
    departments: [{ id: 3, code: 'IT', name: 'Teknologi Informasi' }],
    targetDepartments: [{ id: 7, code: 'ENG', name: 'Engineering' }],
    categories: [
        { id: 2, code: 'PRB', name: 'Perbaikan' },
        { id: 5, code: 'PGD', name: 'Pengadaan' },
    ],
};

const mountFilters = (
    filters: Partial<WorkOrderFilters> = {},
    overrides: { departments?: null; canRestore?: boolean } = {},
) =>
    mount(WorkOrderListFilters, {
        props: {
            filters: { ...EMPTY_WORK_ORDER_FILTERS, ...filters },
            ...options,
            canRestore: true,
            ...overrides,
        },
        attachTo: document.body,
    });

const openPopover = async (wrapper: ReturnType<typeof mountFilters>) => {
    await wrapper.get('[data-test="filter-popover-trigger"]').trigger('click');
    await flushPromises();
};

const inBody = (selector: string) => document.body.querySelector(selector);

/** The query of the last list reload. */
const lastQuery = () => routerGet.mock.lastCall?.[1];

const chipLabels = (wrapper: ReturnType<typeof mountFilters>) =>
    wrapper.findAll('[data-test="filter-chip"]').map((chip) => chip.text());

enableAutoUnmount(afterEach);

describe('WorkOrderListFilters', () => {
    beforeEach(() => {
        routerGet.mockClear();
    });

    it('offers Departemen and Tampilkan terhapus to users who may use them', async () => {
        const wrapper = mountFilters();
        await openPopover(wrapper);

        expect(inBody('[data-test="filter-department"]')).not.toBeNull();
        expect(inBody('[data-test="filter-trashed"]')).not.toBeNull();
    });

    it('hides Departemen for client users and Tampilkan terhapus without restore', async () => {
        const wrapper = mountFilters(
            {},
            { departments: null, canRestore: false },
        );
        await openPopover(wrapper);

        expect(inBody('[data-test="filter-category"]')).not.toBeNull();
        expect(inBody('[data-test="filter-department"]')).toBeNull();
        expect(inBody('[data-test="filter-trashed"]')).toBeNull();
    });

    it('offers Departemen tujuan and Terlambat to every user', async () => {
        const wrapper = mountFilters(
            {},
            { departments: null, canRestore: false },
        );
        await openPopover(wrapper);

        expect(inBody('[data-test="filter-target"]')).not.toBeNull();
        expect(inBody('[data-test="filter-overdue"]')).not.toBeNull();
    });

    it('opens the dashboard "Menunggu Pembayaran" link with its payment chip, counted on the Filter button', async () => {
        const wrapper = mountFilters({ payment: 'ditagih' });

        expect(chipLabels(wrapper)).toEqual(['Pembayaran: Ditagih']);
        expect(
            wrapper.get('[data-test="filter-popover-trigger"]').text(),
        ).toContain('1');

        await openPopover(wrapper);

        expect(inBody('[data-test="filter-payment"]')).not.toBeNull();
    });

    it('opens a target and overdue URL with their chips, counted on the Filter button', () => {
        const wrapper = mountFilters({ target: '7', overdue: true });

        expect(chipLabels(wrapper)).toEqual(['Dept. tujuan: ENG', 'Terlambat']);
        expect(
            wrapper.get('[data-test="filter-popover-trigger"]').text(),
        ).toContain('2');
    });

    it('reloads without overdue when its chip is removed', async () => {
        const wrapper = mountFilters({ target: '7', overdue: true });

        const overdueChip = wrapper
            .findAll('[data-test="filter-chip"]')
            .find((chip) => chip.text() === 'Terlambat');
        await overdueChip?.trigger('click');
        await flushPromises();

        expect(lastQuery()).toEqual({ target: '7' });
    });

    it('sends overdue as 1 when Hanya yang terlambat is checked', async () => {
        const wrapper = mountFilters();
        await openPopover(wrapper);

        (inBody('#filter-overdue') as HTMLElement).click();
        await flushPromises();

        expect(lastQuery()).toEqual({ overdue: 1 });
    });

    it('sends missing_report as 1 when Hanya yang belum lapor is checked, and clears it with its chip', async () => {
        const wrapper = mountFilters();
        await openPopover(wrapper);

        (inBody('#filter-missing-report') as HTMLElement).click();
        await flushPromises();

        expect(lastQuery()).toEqual({ missing_report: 1 });

        const reported = mountFilters({ missing_report: true });
        expect(chipLabels(reported)).toEqual(['Belum lapor']);
        expect(
            reported.get('[data-test="filter-popover-trigger"]').text(),
        ).toContain('1');

        await reported
            .findAll('[data-test="filter-chip"]')
            .find((chip) => chip.text() === 'Belum lapor')
            ?.trigger('click');
        await flushPromises();

        expect(lastQuery()).toEqual({});
    });

    it('opens an existing filtered URL with its chips, and none for the sort', () => {
        const wrapper = mountFilters({
            status: 'diajukan',
            urgency: 'mendesak',
            category: '2',
            from: '2026-09-01',
            to: '2026-09-27',
            trashed: true,
            sort: 'urgensi',
        });

        expect(chipLabels(wrapper)).toEqual([
            'Status: Diajukan',
            'Urgensi: Mendesak',
            'Kategori: Perbaikan',
            'Dibuat: 1 Sep 2026 – 27 Sep 2026',
            'Terhapus',
        ]);
    });

    it('opens the dashboard "WO Mendesak" link with its two chips', () => {
        const wrapper = mountFilters({
            status: 'aktif',
            urgency: 'mendesak',
        });

        expect(chipLabels(wrapper)).toEqual([
            'Status: Aktif',
            'Urgensi: Mendesak',
        ]);
    });

    it('shows no chips for the dashboard "WO Terbaru" link, which only sorts', () => {
        const wrapper = mountFilters({ sort: 'diperbarui' });

        expect(wrapper.find('[data-test="active-filters"]').exists()).toBe(
            false,
        );
    });

    it('reloads without a filter whose chip is removed', async () => {
        const wrapper = mountFilters({
            status: 'diajukan',
            from: '2026-09-01',
            to: '2026-09-27',
            sort: 'urgensi',
        });

        const dateChip = wrapper
            .findAll('[data-test="filter-chip"]')
            .find((chip) => chip.text().startsWith('Dibuat'));
        await dateChip?.trigger('click');
        await flushPromises();

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(lastQuery()).toEqual({ status: 'diajukan', sort: 'urgensi' });
    });

    it('reloads once when a reset also clears the search', async () => {
        vi.useFakeTimers();
        const wrapper = mountFilters({ search: 'pompa', status: 'diajukan' });

        await wrapper.get('[data-test="filter-reset"]').trigger('click');
        await vi.advanceTimersByTimeAsync(1000);
        vi.useRealTimers();

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(lastQuery()).toEqual({});
    });

    it('reloads at once when the search chip is removed', async () => {
        vi.useFakeTimers();
        const wrapper = mountFilters({ search: 'pompa', status: 'diajukan' });

        await wrapper.findAll('[data-test="filter-chip"]')[0].trigger('click');
        await vi.advanceTimersByTimeAsync(0);
        const callsBeforeDebounce = routerGet.mock.calls.length;
        await vi.advanceTimersByTimeAsync(1000);
        vi.useRealTimers();

        expect(callsBeforeDebounce).toBe(1);
        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(lastQuery()).toEqual({ status: 'diajukan' });
    });

    it('resets every filter but keeps the sort', async () => {
        const wrapper = mountFilters({
            search: 'pompa',
            status: 'diajukan',
            department: '3',
            target: '7',
            overdue: true,
            trashed: true,
            sort: 'urgensi',
        });

        await wrapper.get('[data-test="filter-reset"]').trigger('click');
        await flushPromises();

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(lastQuery()).toEqual({ sort: 'urgensi' });
    });

    it('keeps the Filter popover open while Kategori changes', async () => {
        const wrapper = mountFilters();
        await openPopover(wrapper);

        const category = wrapper
            .findAllComponents(FilterSelect)
            .find((select) => select.props('label') === 'Filter kategori');
        category?.vm.$emit('update:modelValue', '5');
        await flushPromises();

        expect(lastQuery()).toEqual({ category: '5' });
        expect(routerGet.mock.lastCall?.[2]).toMatchObject({
            preserveState: true,
            preserveScroll: true,
        });
        expect(inBody('[data-test="filter-popover"]')).not.toBeNull();
    });

    it('does not reload after only the first date of a range', async () => {
        const wrapper = mountFilters();
        await openPopover(wrapper);

        (inBody('[data-test="date-range-trigger"]') as HTMLElement).click();
        await flushPromises();
        const day = inBody(
            '[data-test="date-range-calendar"] [data-value]:not([data-outside-view])',
        ) as HTMLElement;
        day.focus();
        day.click();
        await flushPromises();

        expect(routerGet).not.toHaveBeenCalled();
    });

    it('counts the active filters behind each Filter button', () => {
        const wrapper = mountFilters({
            status: 'diajukan',
            category: '2',
            from: '2026-09-01',
            trashed: true,
            sort: 'urgensi',
        });

        expect(
            wrapper
                .get(
                    '[data-test="filter-popover-trigger"] [data-test="filter-count"]',
                )
                .text(),
        ).toBe('3');
        expect(
            wrapper
                .get(
                    '[data-test="filter-sheet-trigger"] [data-test="filter-count"]',
                )
                .text(),
        ).toBe('4');
    });
});
