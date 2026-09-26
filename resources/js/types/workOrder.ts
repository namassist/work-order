import type { DepartmentOption } from './admin';

/** Badge colour tokens from docs/DESIGN.md. */
export type StatusTone =
    | 'secondary'
    | 'warning'
    | 'info'
    | 'success'
    | 'destructive';

export type WorkOrderStatusOption = {
    value: string;
    label: string;
    tone: string;
};

export type CategoryOption = {
    id: number;
    code: string;
    name: string;
};

export type WorkOrder = {
    id: number;
    number: string | null;
    /** The number, or "Draft" before submission. */
    display_number: string;
    title: string;
    description: string | null;
    status: WorkOrderStatusOption;
    /** Date-only (Y-m-d); format with formatCalendarDate. */
    target_date: string | null;
    department: DepartmentOption;
    category: CategoryOption;
    requester: { id: number; name: string };
    created_at: string;
    deleted_at: string | null;
};

export type WorkOrderListItem = WorkOrder & {
    can: { update: boolean; delete: boolean; restore: boolean };
};

export type WorkOrderTransition = {
    value: string;
    /** Button label, e.g. "Ajukan". */
    label: string;
    /** Tone of the target status; destructive ones get a destructive button. */
    tone: string;
    requires_note: boolean;
};

export type StatusHistoryEntry = {
    id: number;
    from: { value: string; label: string } | null;
    to: { value: string; label: string };
    user: { id: number; name: string };
    note: string | null;
    created_at: string;
};
