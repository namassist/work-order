import { describe, expect, it } from 'vite-plus/test';
import type { WorkOrderFilterOptions } from '@/lib/workOrderFilters';
import {
    EMPTY_WORK_ORDER_FILTERS,
    workOrderFilterChips,
} from '@/lib/workOrderFilters';

const options: WorkOrderFilterOptions = {
    statuses: [{ value: 'diajukan', label: 'Diajukan', tone: 'warning' }],
    statusGroups: [{ value: 'aktif', label: 'Aktif' }],
    urgencies: [{ value: 'mendesak', label: 'Mendesak' }],
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
    categories: [{ id: 2, code: 'PRB', name: 'Perbaikan' }],
};

describe('workOrderFilterChips', () => {
    it('has no chips without filters, whatever the sort', () => {
        expect(
            workOrderFilterChips(
                { ...EMPTY_WORK_ORDER_FILTERS, sort: 'urgensi' },
                options,
            ),
        ).toEqual([]);
    });

    it('labels each active filter from its options', () => {
        expect(
            workOrderFilterChips(
                {
                    search: 'pompa',
                    status: 'diajukan',
                    urgency: 'mendesak',
                    payment: 'ditagih',
                    department: '3',
                    target: '7',
                    category: '2',
                    overdue: true,
                    missing_report: true,
                    from: '2026-09-01',
                    to: '2026-09-27',
                    trashed: true,
                    sort: 'diperbarui',
                },
                options,
            ),
        ).toEqual([
            { key: 'search', label: 'Cari: “pompa”' },
            { key: 'status', label: 'Status: Diajukan' },
            { key: 'urgency', label: 'Urgensi: Mendesak' },
            { key: 'payment', label: 'Pembayaran: Ditagih' },
            { key: 'department', label: 'Dept. pemohon: IT' },
            { key: 'target', label: 'Dept. tujuan: ENG' },
            { key: 'category', label: 'Kategori: Perbaikan' },
            { key: 'overdue', label: 'Terlambat' },
            { key: 'missing_report', label: 'Belum lapor' },
            { key: 'created', label: 'Dibuat: 1 Sep 2026 – 27 Sep 2026' },
            { key: 'trashed', label: 'Terhapus' },
        ]);
    });

    it('gives one date chip for a range open on one side', () => {
        expect(
            workOrderFilterChips(
                { ...EMPTY_WORK_ORDER_FILTERS, to: '2026-09-27' },
                options,
            ),
        ).toEqual([{ key: 'created', label: 'Dibuat: Sampai 27 Sep 2026' }]);
    });

    it('falls back to the raw value for an option it does not know', () => {
        expect(
            workOrderFilterChips(
                { ...EMPTY_WORK_ORDER_FILTERS, department: '9' },
                { ...options, departments: null },
            ),
        ).toEqual([{ key: 'department', label: 'Dept. pemohon: 9' }]);
    });

    it('labels a status group like a status', () => {
        expect(
            workOrderFilterChips(
                { ...EMPTY_WORK_ORDER_FILTERS, status: 'aktif' },
                options,
            ),
        ).toEqual([{ key: 'status', label: 'Status: Aktif' }]);
    });
});
