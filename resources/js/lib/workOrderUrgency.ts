import type { LucideIcon } from '@lucide/vue';
import { ChevronDown, ChevronsUp, ChevronUp, Minus } from '@lucide/vue';

/**
 * Urgency is shown as an icon plus its label, never with the status colours
 * (docs/DESIGN.md › Urgensi Work Order). Values come from
 * App\Enums\WorkOrderUrgency.
 */
const ICONS: Record<string, LucideIcon> = {
    rendah: ChevronDown,
    normal: Minus,
    tinggi: ChevronUp,
    mendesak: ChevronsUp,
};

/** The one urgency that gets visual emphasis. */
export const URGENT = 'mendesak';

export function urgencyIcon(value: string): LucideIcon {
    return ICONS[value] ?? Minus;
}

export function isUrgent(value: string): boolean {
    return value === URGENT;
}
