import type { NavGroup, NavItem } from '@/types';

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

/**
 * Keeps each group's visible items and drops groups left empty, so a user
 * never sees a heading with nothing under it.
 */
export function visibleNavGroups(
    groups: NavGroup[],
    can: (permission: string) => boolean,
): NavGroup[] {
    return groups
        .map((group) => ({
            ...group,
            items: visibleNavItems(group.items, can),
        }))
        .filter((group) => group.items.length > 0);
}
