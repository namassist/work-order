import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { UrgentWorkOrder } from '@/types';
import UrgentWorkOrders from './UrgentWorkOrders.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { displayTimezone: 'Asia/Makassar' } }),
}));

const workOrder: UrgentWorkOrder = {
    id: 12,
    number: 'WO/PRD/2026/09/0003',
    title: 'Pompa air mati',
    category: 'Perbaikan',
    requester_name: 'Eko Purnomo',
    status: { value: 'pelaksanaan', label: 'Pelaksanaan', tone: 'info' },
    submitted_at: '2026-09-20T01:00:00+00:00',
};

describe('UrgentWorkOrders', () => {
    it('links "Lihat semua" to the list filtered exactly like the panel', () => {
        const wrapper = mount(UrgentWorkOrders, { props: { items: [] } });

        const href = new URL(
            wrapper.get('[data-test="urgent-list-link"]').attributes('href')!,
            'http://localhost',
        );

        expect(href.pathname).toBe('/work-orders');
        expect(Object.fromEntries(href.searchParams)).toEqual({
            urgency: 'mendesak',
            status: 'aktif',
        });
    });

    it("shows each work order's status", () => {
        const wrapper = mount(UrgentWorkOrders, {
            props: { items: [workOrder] },
        });

        expect(wrapper.get('[data-test="urgent-status"]').text()).toBe(
            'Pelaksanaan',
        );
    });
});
