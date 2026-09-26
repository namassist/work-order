import type { StatusTone } from '@/types';

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
};

export function statusToneChartColor(tone: string): string {
    return TONE_CHART_COLORS[tone as StatusTone] ?? TONE_CHART_COLORS.secondary;
}
