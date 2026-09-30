import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { WorkOrderBast } from '@/types';
import BastSection from './BastSection.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { displayTimezone: 'Asia/Makassar' } }),
}));

const bast = (overrides: Partial<WorkOrderBast> = {}): WorkOrderBast => ({
    number: 'BAST/2026/09/0001',
    template_version: 2,
    submitted_by: 'Agus Setiawan',
    submitted_at: '2026-09-29T07:30:00+00:00',
    approver_name: null,
    approved_at: null,
    final_sha256: null,
    file: {
        id: '0192f3a4-5b6c-7d8e-9f01-23456789abcd',
        name: 'BAST-2026-09-0001-draf.pdf',
        size: 20480,
        extension: 'pdf',
        previewable: true,
        uploader: { id: 1, name: 'Agus Setiawan' },
        created_at: '2026-09-29T07:30:00+00:00',
    },
    ...overrides,
});

describe('BastSection', () => {
    it('shows a draft waiting for the Direktur', () => {
        const text = mount(BastSection, { props: { bast: bast() } }).text();

        expect(text).toContain('Draf');
        expect(text).toContain('BAST/2026/09/0001');
        expect(text).toContain('Menunggu persetujuan Direktur');
        expect(text).toContain('BAST-2026-09-0001-draf.pdf');
        expect(text).not.toContain('SHA-256');
    });

    it('shows the approval and the final PDF hash', () => {
        const hash = 'a'.repeat(64);
        const text = mount(BastSection, {
            props: {
                bast: bast({
                    approver_name: 'Hartono Wijaya',
                    approved_at: '2026-09-30T02:15:00+00:00',
                    final_sha256: hash,
                }),
            },
        }).text();

        expect(text).toContain('Final');
        expect(text).toContain('Hartono Wijaya');
        expect(text).toContain('30 Sep 2026 10:15');
        expect(text).toContain(hash);
    });
});
