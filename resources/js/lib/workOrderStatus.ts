import type { StatusTone, WorkOrderStatusOption } from '@/types';

/**
 * Badge classes per status tone (docs/DESIGN.md › Status Work Order). The
 * server decides which tone each status has; see App\States\WorkOrder.
 */
const TONE_CLASSES: Record<StatusTone, string> = {
    secondary: 'bg-secondary text-secondary-foreground',
    warning: 'bg-warning text-warning-foreground',
    info: 'bg-info text-info-foreground',
    success: 'bg-success text-success-foreground',
    destructive: 'bg-destructive/10 text-destructive',
    // Ended, nothing left to do: an outline in the muted text colour.
    muted: 'border-border bg-transparent text-muted-foreground',
};

export function statusToneClass(tone: string): string {
    return TONE_CLASSES[tone as StatusTone] ?? TONE_CLASSES.secondary;
}

/**
 * Chart fill per status tone: the badge token, unless it is under 3:1
 * against the card in light mode; those use a --chart-* token of the same
 * family instead (docs/DESIGN.md › Status Work Order).
 */
const TONE_CHART_COLORS: Record<StatusTone, string> = {
    secondary: 'var(--chart-5)',
    warning: 'var(--chart-submitted)',
    info: 'var(--info)',
    success: 'var(--success)',
    destructive: 'var(--destructive)',
    muted: 'var(--chart-cancelled)',
};

/**
 * Chart fills for statuses whose tone alone does not give them their own
 * series colour; every other status uses its tone's fill.
 */
const STATUS_CHART_COLORS: Record<string, string> = {
    dibatalkan: 'var(--chart-cancelled)',
};

export function statusChartColor(
    status: Pick<WorkOrderStatusOption, 'value' | 'tone'>,
): string {
    return (
        STATUS_CHART_COLORS[status.value] ??
        TONE_CHART_COLORS[status.tone as StatusTone] ??
        TONE_CHART_COLORS.secondary
    );
}
