import { AlarmClock, Hourglass, ReceiptText, Wrench } from '@lucide/vue';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import type { StatItem } from '@/components/StatStrip.vue';

/** DashboardController::workOrderCounts(), over the WOs the user may see. */
export type WorkOrderCounts = {
    submitted: number;
    in_progress: number;
    billing: number;
    overdue: number;
    /** Terlambat split by the date it is late against (WorkOrderDeadline). */
    overdue_by: { target_date: number; payment_due_date: number };
};

/**
 * Terlambat's breakdown, e.g. "3 lewat target · 2 lewat jatuh tempo",
 * leaving out a kind with nothing late; undefined when nothing is.
 */
export function overdueHint(counts: WorkOrderCounts): string | undefined {
    const parts = [
        counts.overdue_by.target_date > 0
            ? `${counts.overdue_by.target_date} lewat target`
            : null,
        counts.overdue_by.payment_due_date > 0
            ? `${counts.overdue_by.payment_due_date} lewat jatuh tempo`
            : null,
    ].filter((part): part is string => part !== null);

    return parts.length > 0 ? parts.join(' · ') : undefined;
}

/**
 * The dashboard's stat strip: WOs waiting to be accepted (Diajukan), being
 * worked on (Dikerjakan), waiting for payment (Penagihan), and late
 * (WorkOrder::overdue(), with its breakdown). Each card opens the list with
 * the same filter. Undefined counts are still loading; null ones are not
 * available to the user.
 */
export function dashboardMetrics(
    counts: WorkOrderCounts | null | undefined,
): StatItem[] {
    const card = (
        label: string,
        icon: StatItem['icon'],
        count: (counts: WorkOrderCounts) => number,
        query: Record<string, string | number>,
    ): StatItem => ({
        label,
        icon,
        value: counts === undefined ? undefined : counts ? count(counts) : null,
        href: counts ? WorkOrderController.index.url({ query }) : undefined,
    });

    return [
        card('Menunggu Diterima', Hourglass, (c) => c.submitted, {
            status: 'diajukan',
        }),
        card('Dikerjakan', Wrench, (c) => c.in_progress, {
            status: 'dikerjakan',
        }),
        card('Menunggu Pembayaran', ReceiptText, (c) => c.billing, {
            status: 'penagihan',
        }),
        {
            ...card('Terlambat', AlarmClock, (c) => c.overdue, { overdue: 1 }),
            hint: counts ? overdueHint(counts) : undefined,
        },
    ];
}
