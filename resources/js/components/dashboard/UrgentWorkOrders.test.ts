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
    number: 'WO/IT/2026/09/0003',
    title: 'Server mati',
    category: 'Perbaikan',
    requester: { id: 4, name: 'Dewi Lestari' },
    submitted_at: '2026-09-24T23:30:00+00:00',
};

describe('UrgentWorkOrders', () => {
    it('links "Lihat semua" to the list filtered to submitted mendesak work orders', () => {
        const href = mount(UrgentWorkOrders, { props: { items: [] } })
            .get('[data-test="urgent-list-link"]')
            .attributes('href');
        const query = new URL(href ?? '', 'http://localhost').searchParams;

        expect(query.get('status')).toBe('diajukan');
        expect(query.get('urgency')).toBe('mendesak');
    });

    it('says in one line that nothing is waiting', () => {
        const wrapper = mount(UrgentWorkOrders, { props: { items: [] } });

        expect(wrapper.get('[data-test="urgent-empty"]').text()).toBe(
            'Tidak ada WO mendesak yang menunggu.',
        );
    });

    it('links each row to its work order and shows the full WITA submission time on hover', () => {
        const wrapper = mount(UrgentWorkOrders, {
            props: { items: [workOrder] },
        });

        expect(wrapper.get('li a').attributes('href')).toMatch(
            /\/work-orders\/12$/,
        );
        expect(wrapper.text()).toContain('Perbaikan · Dewi Lestari');
        expect(
            wrapper.get('[data-test="submitted-at"]').attributes('title'),
        ).toBe('25 Sep 2026 07:30');
    });
});
