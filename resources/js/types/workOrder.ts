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

/** docs/DESIGN.md › Urgensi Work Order; never shown with status colours. */
export type WorkOrderUrgencyOption = {
    value: string;
    label: string;
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
    urgency: WorkOrderUrgencyOption;
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
    type: 'status';
    id: number;
    from: { value: string; label: string } | null;
    to: { value: string; label: string };
    user: { id: number; name: string };
    note: string | null;
    created_at: string;
};

export type CommentEntry = {
    type: 'comment';
    id: number;
    user: { id: number; name: string };
    /** Plain text; null once deleted. Never render as HTML. */
    body: string | null;
    deleted: boolean;
    edited: boolean;
    created_at: string;
    can: { update: boolean; delete: boolean };
};

/** One event of a work order's timeline, oldest first. */
export type TimelineEntry = StatusHistoryEntry | CommentEntry;

export type WorkOrderCommentSettings = {
    max_length: number;
    /** The status no longer accepts comments (e.g. Dibatalkan). */
    read_only: boolean;
};
