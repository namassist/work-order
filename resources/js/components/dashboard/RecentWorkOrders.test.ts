import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { WorkOrder } from '@/types';
import RecentWorkOrders from './RecentWorkOrders.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { displayTimezone: 'Asia/Makassar' } }),
}));

const workOrder: WorkOrder = {
    id: 12,
    number: 'WO/IT/2026/09/0003',
    display_number: 'WO/IT/2026/09/0003',
    title: 'Server mati',
    description: 'Ruang server lantai 2.',
    status: { value: 'diajukan', label: 'Diajukan', tone: 'warning' },
    urgency: { value: 'mendesak', label: 'Mendesak' },
    target_date: null,
    requester_department: { id: 1, code: 'PRD', name: 'Produksi' },
    target_department: { id: 5, code: 'IT', name: 'Teknologi Informasi' },
    category: { id: 2, code: 'PRB', name: 'Perbaikan' },
    requester_name: 'Dewi Lestari',
    pic_name: null,
    entered_by: { name: 'Dewi Lestari' },
    created_at: '2026-09-20T01:00:00+00:00',
    updated_at: '2026-09-24T23:30:00+00:00',
    deleted_at: null,
};

describe('RecentWorkOrders', () => {
    it('links "Lihat semua" to the list sorted by last activity', () => {
        const href = mount(RecentWorkOrders, { props: { items: [] } })
            .get('[data-test="recent-list-link"]')
            .attributes('href');

        expect(
            new URL(href ?? '', 'http://localhost').searchParams.get('sort'),
        ).toBe('diperbarui');
    });

    it('shows skeleton rows while loading', () => {
        const wrapper = mount(RecentWorkOrders);

        expect(wrapper.findAll('[data-test="recent-skeleton"]')).toHaveLength(
            4,
        );
    });

    it('says in one line that there are no work orders', () => {
        const wrapper = mount(RecentWorkOrders, { props: { items: [] } });

        expect(wrapper.get('[data-test="recent-empty"]').text()).toBe(
            'Belum ada work order.',
        );
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('links each row to its work order and shows the full WITA time on hover', () => {
        const wrapper = mount(RecentWorkOrders, {
            props: { items: [workOrder] },
        });

        expect(wrapper.get('tbody a').attributes('href')).toMatch(
            /\/work-orders\/12$/,
        );
        expect(wrapper.text()).toContain('Server mati');
        expect(wrapper.text()).toContain('Dewi Lestari');
        expect(
            wrapper.get('[data-test="updated-at"]').attributes('title'),
        ).toBe('25 Sep 2026 07:30');
    });
});
