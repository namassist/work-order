import { formatCalendarDateRange } from '@/lib/format';
import type {
    CategoryOption,
    DepartmentOption,
    WorkOrderStatusOption,
    WorkOrderUrgencyOption,
} from '@/types';

/** The Work Order list's query as the server validated it. */
export type WorkOrderFilters = {
    search: string;
    status: string;
    urgency: string;
    department: string;
    category: string;
    /** Created on or after this WITA calendar day (Y-m-d). */
    from: string;
    /** Created on or before this WITA calendar day (Y-m-d). */
    to: string;
    trashed: boolean;
    /**
     * '' for newest first, 'urgensi' for most urgent first, or 'diperbarui'
     * for most recently active first. Not a filter.
     */
    sort: string;
};

export const EMPTY_WORK_ORDER_FILTERS: WorkOrderFilters = {
    search: '',
    status: '',
    urgency: '',
    department: '',
    category: '',
    from: '',
    to: '',
    trashed: false,
    sort: '',
};

export type WorkOrderFilterOptions = {
    statuses: WorkOrderStatusOption[];
    urgencies: WorkOrderUrgencyOption[];
    /** Null unless the user sees every department's work orders. */
    departments: DepartmentOption[] | null;
    categories: CategoryOption[];
};

/** A chip's key names what removing it clears; `created` is both dates. */
export type WorkOrderFilterChipKey =
    | 'search'
    | 'status'
    | 'urgency'
    | 'department'
    | 'category'
    | 'created'
    | 'trashed';

export type WorkOrderFilterChip = {
    key: WorkOrderFilterChipKey;
    label: string;
};

/**
 * One chip per filter narrowing the list, labelled from the page's options.
 * The sort changes the order, not which work orders match, so it has none.
 */
export function workOrderFilterChips(
    filters: WorkOrderFilters,
    options: WorkOrderFilterOptions,
): WorkOrderFilterChip[] {
    const byId = <T extends { id: number }>(items: T[] | null, id: string) =>
        items?.find((item) => String(item.id) === id);
    const chips: WorkOrderFilterChip[] = [];

    if (filters.search) {
        chips.push({ key: 'search', label: `Cari: “${filters.search}”` });
    }

    if (filters.status) {
        const status = options.statuses.find(
            (option) => option.value === filters.status,
        );
        chips.push({
            key: 'status',
            label: `Status: ${status?.label ?? filters.status}`,
        });
    }

    if (filters.urgency) {
        const urgency = options.urgencies.find(
            (option) => option.value === filters.urgency,
        );
        chips.push({
            key: 'urgency',
            label: `Urgensi: ${urgency?.label ?? filters.urgency}`,
        });
    }

    if (filters.department) {
        const department = byId(options.departments, filters.department);
        chips.push({
            key: 'department',
            label: `Departemen: ${department?.code ?? filters.department}`,
        });
    }

    if (filters.category) {
        const category = byId(options.categories, filters.category);
        chips.push({
            key: 'category',
            label: `Kategori: ${category?.name ?? filters.category}`,
        });
    }

    if (filters.from || filters.to) {
        chips.push({
            key: 'created',
            label: `Dibuat: ${formatCalendarDateRange(filters.from, filters.to)}`,
        });
    }

    if (filters.trashed) {
        chips.push({ key: 'trashed', label: 'Terhapus' });
    }

    return chips;
}
