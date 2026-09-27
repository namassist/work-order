import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    /** Omitted for a section label (e.g. "Master Data") that has no page. */
    href?: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Permission required to see the item; omitted means always visible. */
    permission?: string;
    /** A count shown beside the title (e.g. pending registrations); hidden when 0 or null. */
    badge?: number | null;
};

export type NavGroup = {
    /** Heading shown above the items; omitted for the top group. */
    label?: string;
    /** Executor company (Unggul) navigation, never shown to client company users. */
    internal?: boolean;
    items: NavItem[];
};
