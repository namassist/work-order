import { describe, expect, it } from 'vite-plus/test';
import type { WorkOrderCounts } from '@/lib/dashboardMetrics';
import {
    dashboardMetrics,
    missingReportHint,
    overdueHint,
} from '@/lib/dashboardMetrics';

const counts: WorkOrderCounts = {
    submitted: 4,
    in_progress: 3,
    billing: 2,
    missing_report: 1,
    overdue: 5,
    overdue_by: { target_date: 3, payment_due_date: 2 },
};

describe('dashboardMetrics', () => {
    it('has one card per status and Terlambat, each linking to its filtered list', () => {
        expect(
            dashboardMetrics(counts).map(({ label, value, href }) => ({
                label,
                value,
                href,
            })),
        ).toEqual([
            {
                label: 'Menunggu Diterima',
                value: 4,
                href: '/work-orders?status=diajukan',
            },
            {
                label: 'Pelaksanaan',
                value: 3,
                href: '/work-orders?status=pelaksanaan',
            },
            {
                label: 'Menunggu Pembayaran',
                value: 2,
                href: '/work-orders?payment=ditagih',
            },
            { label: 'Terlambat', value: 5, href: '/work-orders?overdue=1' },
        ]);
    });

    it('keeps the cards loading while the counts load, and links nowhere', () => {
        expect(
            dashboardMetrics(undefined).map(({ value, href }) => ({
                value,
                href,
            })),
        ).toEqual(Array(4).fill({ value: undefined, href: undefined }));
    });
});

describe('overdueHint', () => {
    it('splits Terlambat by the date it is late against', () => {
        expect(overdueHint(counts)).toBe(
            '3 lewat target · 2 lewat jatuh tempo',
        );
    });

    it('leaves out a kind with nothing late, and says nothing when none is', () => {
        expect(
            overdueHint({
                ...counts,
                overdue: 1,
                overdue_by: { target_date: 0, payment_due_date: 1 },
            }),
        ).toBe('1 lewat jatuh tempo');
        expect(
            overdueHint({
                ...counts,
                overdue: 0,
                overdue_by: { target_date: 0, payment_due_date: 0 },
            }),
        ).toBeUndefined();
    });
});

describe('missingReportHint', () => {
    it("counts Pelaksanaan work orders without today's report, on the Pelaksanaan card", () => {
        expect(missingReportHint(counts)).toBe('1 belum lapor hari ini');
        expect(dashboardMetrics(counts)[1]?.hint).toBe(
            '1 belum lapor hari ini',
        );
        expect(
            missingReportHint({ ...counts, missing_report: 0 }),
        ).toBeUndefined();
    });
});
