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
