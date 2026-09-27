import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import DateRangeFilter from './DateRangeFilter.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { displayTimezone: 'Asia/Makassar' } }),
}));

const mountOpen = async (from = '', to = '') => {
    const wrapper = mount(DateRangeFilter, {
        props: { from, to, label: 'Dibuat' },
        attachTo: document.body,
    });
    await wrapper.get('[data-test="date-range-trigger"]').trigger('click');
    await flushPromises();

    return wrapper;
};

const inBody = (selector: string) =>
    document.body.querySelector<HTMLElement>(selector);

/** reka-ui ends a range only on a day it saw hovered or focused first. */
const clickDay = async (date: string) => {
    const cell = inBody(
        `[data-test="date-range-calendar"] [data-value="${date}"]`,
    );
    cell?.focus();
    cell?.click();
    await flushPromises();
};

enableAutoUnmount(afterEach);

describe('DateRangeFilter', () => {
    beforeEach(() => {
        // 01:30 WITA on 1 Oct 2026, still 30 Sep in UTC.
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-09-30T17:30:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('names the current range on its trigger', () => {
        const wrapper = mount(DateRangeFilter, {
            props: { from: '2026-09-01', to: '2026-09-27', label: 'Dibuat' },
        });

        expect(wrapper.get('[data-test="date-range-trigger"]').text()).toBe(
            '1 Sep 2026 – 27 Sep 2026',
        );
    });

    it('applies a preset at once, counted in WITA', async () => {
        const wrapper = await mountOpen();

        inBody('[data-test="date-range-preset-last7Days"]')?.click();
        await flushPromises();

        expect(wrapper.emitted('update:range')).toEqual([
            [{ from: '2026-09-25', to: '2026-10-01' }],
        ]);
    });

    it('waits for the second date before applying a picked range', async () => {
        const wrapper = await mountOpen();

        await clickDay('2026-10-01');
        expect(wrapper.emitted('update:range')).toBeUndefined();

        await clickDay('2026-10-05');
        expect(wrapper.emitted('update:range')).toEqual([
            [{ from: '2026-10-01', to: '2026-10-05' }],
        ]);
    });

    it('clears both ends at once', async () => {
        const wrapper = await mountOpen('2026-09-01', '2026-09-27');

        inBody('[data-test="date-range-clear"]')?.click();
        await flushPromises();

        expect(wrapper.emitted('update:range')).toEqual([
            [{ from: '', to: '' }],
        ]);
    });
});
