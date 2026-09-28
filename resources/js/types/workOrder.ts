import type { DepartmentOption } from './admin';

/** Badge colour tokens from docs/DESIGN.md. */
export type StatusTone =
    | 'secondary'
    | 'warning'
    | 'info'
    | 'billing'
    | 'success'
    | 'destructive'
    | 'muted';

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
    /** The client company (IC) department that requests the work. */
    requester_department: DepartmentOption;
    /** The executor company department it is addressed to; null until chosen. */
    target_department: DepartmentOption | null;
    category: CategoryOption;
    /** The requester: an account, or a contact name (id null) when entered on behalf. */
    requester: { id: number | null; name: string };
    /** Who entered the work order: the requester, or a koordinator on their behalf. */
    entered_by: { name: string };
    /** Entered by someone other than the requester (a koordinator). */
    entered_on_behalf: boolean;
    created_at: string;
    /** Last activity: edits, comments, status changes, and attachments. */
    updated_at: string;
    deleted_at: string | null;
};

export type WorkOrderListItem = WorkOrder & {
    can: { update: boolean; delete: boolean; restore: boolean };
};

/** A status change this user may perform (FLOW.md §5). */
export type WorkOrderTransition = {
    value: string;
    /** Button label, e.g. "Ajukan", or "Ajukan ulang" after a rejection. */
    label: string;
    /** Rejecting or cancelling: a destructive button. */
    destructive: boolean;
    requires_note: boolean;
    /** E.g. "Alasan penolakan"; "Catatan" when nothing more specific fits. */
    note_label: string;
    /** The target status needs a target department (e.g. Diajukan). */
    requires_target_department: boolean;
    /** Entered through its own form instead of the note dialog. */
    form: 'invoice' | 'payment' | null;
    /** Why this user may not make the change (segregation of duties). */
    blocked_reason: string | null;
};

/** The invoice of a work order (FLOW.md §8), from Penagihan on. */
export type WorkOrderInvoice = {
    number: string;
    /** Date-only values (Y-m-d); format with formatCalendarDate. */
    invoice_date: string;
    /** Decimal string, e.g. "1500000.00"; format with formatRupiah. */
    amount: string | null;
    due_date: string | null;
    paid_on: string | null;
    /** Unpaid and past its due date (WITA). */
    is_overdue: boolean;
    issued_by: { name: string };
    corrected_by: { name: string } | null;
    paid_by: { name: string } | null;
};

/** The reason given when the work order was rejected or cancelled. */
export type WorkOrderStatusNote = {
    label: string;
    note: string;
    user: string;
    created_at: string;
};

export type StatusHistoryEntry = {
    type: 'status';
    id: number;
    from: { value: string; label: string } | null;
    to: { value: string; label: string };
    user: { id: number; name: string };
    note: string | null;
    /** On the creation entry of an on-behalf work order: the requester's name. */
    on_behalf_of: string | null;
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

/** Dashboard "Ringkasan Pengajuan": work orders created per WITA day. */
export type RequestOverview = {
    /** The period in days, 7 or 30. */
    days: number;
    statuses: WorkOrderStatusOption[];
    /** Oldest first; `date` is date-only (Y-m-d), counts keyed by status value. */
    series: { date: string; counts: Record<string, number> }[];
};

/** Dashboard "WO Mendesak": a submitted work order with urgency mendesak. */
export type UrgentWorkOrder = {
    id: number;
    number: string | null;
    title: string;
    category: string;
    requester: { id: number | null; name: string };
    status: WorkOrderStatusOption;
    /** ISO moment of the first submission. */
    submitted_at: string | null;
};

/** An account the koordinator can pick as the requester of an on-behalf work order. */
export type RequesterAccount = {
    id: number;
    name: string;
    email: string;
};

/** The current requester of an on-behalf draft, for the koordinator who entered it. */
export type RequesterCorrection = {
    department_id: number;
    account: RequesterAccount | null;
    contact_name: string | null;
};
