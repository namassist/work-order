import { formatCalendarDateRange } from '@/lib/format';
import type {
    CategoryOption,
    DepartmentOption,
    PaymentStatusOption,
    WorkOrderStatusOption,
    WorkOrderUrgencyOption,
} from '@/types';

/** The Work Order list's query as the server validated it. */
export type WorkOrderFilters = {
    search: string;
    status: string;
    urgency: string;
    /** Payment track of closed work orders: belum_ditagih, ditagih, or lunas. */
    payment: string;
    /** Requester (client company) department id. */
    department: string;
    /** Target (executor company) department id. */
    target: string;
    category: string;
    /** Only work orders late against their status's deadline. */
    overdue: boolean;
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
    payment: '',
    department: '',
    target: '',
    category: '',
    overdue: false,
    from: '',
    to: '',
    trashed: false,
    sort: '',
};

/** A status filter value standing for several statuses, e.g. 'aktif'. */
export type WorkOrderStatusGroupOption = { value: string; label: string };

export type WorkOrderFilterOptions = {
    statuses: WorkOrderStatusOption[];
    statusGroups: WorkOrderStatusGroupOption[];
    urgencies: WorkOrderUrgencyOption[];
    paymentStatuses: PaymentStatusOption[];
    /** Null unless the user sees every department's work orders. */
    departments: DepartmentOption[] | null;
    /** Executor departments; every user may filter by them. */
    targetDepartments: DepartmentOption[];
    categories: CategoryOption[];
};

/** A chip's key names what removing it clears; `created` is both dates. */
export type WorkOrderFilterChipKey =
    | 'search'
    | 'status'
    | 'urgency'
    | 'payment'
    | 'department'
    | 'target'
    | 'category'
    | 'overdue'
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
        const status = [...options.statusGroups, ...options.statuses].find(
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

    if (filters.payment) {
        const payment = options.paymentStatuses.find(
            (option) => option.value === filters.payment,
        );
        chips.push({
            key: 'payment',
            label: `Pembayaran: ${payment?.label ?? filters.payment}`,
        });
    }

    if (filters.department) {
        const department = byId(options.departments, filters.department);
        chips.push({
            key: 'department',
            label: `Dept. pemohon: ${department?.code ?? filters.department}`,
        });
    }

    if (filters.target) {
        const department = byId(options.targetDepartments, filters.target);
        chips.push({
            key: 'target',
            label: `Dept. tujuan: ${department?.code ?? filters.target}`,
        });
    }

    if (filters.category) {
        const category = byId(options.categories, filters.category);
        chips.push({
            key: 'category',
            label: `Kategori: ${category?.name ?? filters.category}`,
        });
    }

    if (filters.overdue) {
        chips.push({ key: 'overdue', label: 'Terlambat' });
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
