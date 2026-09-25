import type { NavItem } from '@/types';

/**
 * Keeps nav items the user may see: those without a permission, and those
 * whose permission the user holds.
 */
export function visibleNavItems(
    items: NavItem[],
    can: (permission: string) => boolean,
): NavItem[] {
    return items.filter(
        (item) => item.permission === undefined || can(item.permission),
    );
}
