import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import { Select } from '@/components/ui/select';
import type { RequestOverview } from '@/types';
import RequestOverviewChart from './RequestOverviewChart.vue';

const statuses = [
    { value: 'draft', label: 'Draft', tone: 'secondary' },
    { value: 'diajukan', label: 'Diajukan', tone: 'warning' },
    { value: 'dibatalkan', label: 'Dibatalkan', tone: 'destructive' },
];

function render(series: RequestOverview['series']) {
    return mount(RequestOverviewChart, {
        props: { overview: { days: 7, statuses, series } },
        global: { stubs: { RequestOverviewBars: true } },
    });
}

describe('RequestOverviewChart', () => {
    it('gives screen readers a table with one row per day and its counts', () => {
        const wrapper = render([
            {
                date: '2026-09-24',
                counts: { draft: 0, diajukan: 2, dibatalkan: 1 },
            },
            {
                date: '2026-09-25',
                counts: { draft: 0, diajukan: 0, dibatalkan: 0 },
            },
        ]);

        const rows = wrapper
            .get('[data-test="overview-table"]')
            .findAll('tbody tr')
            .map((row) => row.findAll('th, td').map((cell) => cell.text()));

        expect(rows).toEqual([
            ['24 Sep 2026', '0', '2', '1', '3'],
            ['25 Sep 2026', '0', '0', '0', '0'],
        ]);
    });

    it('says so in one line instead of drawing empty bars', () => {
        const wrapper = render([
            {
                date: '2026-09-25',
                counts: { draft: 0, diajukan: 0, dibatalkan: 0 },
            },
        ]);

        expect(wrapper.text()).toContain(
            'Belum ada WO dibuat dalam 7 hari terakhir.',
        );
        expect(wrapper.find('[data-test="overview-table"]').exists()).toBe(
            false,
        );
    });
});

describe('RequestOverviewChart period switch', () => {
    it('reloads only the overview with the new period', () => {
        const reload = vi.spyOn(router, 'reload').mockImplementation(() => {});
        const wrapper = render([
            {
                date: '2026-09-25',
                counts: { draft: 1, diajukan: 0, dibatalkan: 0 },
            },
        ]);

        wrapper.findComponent(Select).vm.$emit('update:modelValue', '30');

        expect(reload).toHaveBeenCalledOnce();
        expect(reload.mock.calls[0][0]).toMatchObject({
            only: ['requestOverview'],
            data: { period: '30' },
        });
        reload.mockRestore();
    });

    it('does not reload when the current period is picked again', () => {
        const reload = vi.spyOn(router, 'reload').mockImplementation(() => {});
        const wrapper = render([
            {
                date: '2026-09-25',
                counts: { draft: 1, diajukan: 0, dibatalkan: 0 },
            },
        ]);

        wrapper.findComponent(Select).vm.$emit('update:modelValue', '7');

        expect(reload).not.toHaveBeenCalled();
        reload.mockRestore();
    });
});
