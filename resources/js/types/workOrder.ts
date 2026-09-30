import type { Attachment, AttachmentRules } from './attachment';
import type { DepartmentOption } from './admin';

/** Badge colour tokens from docs/DESIGN.md. */
export type StatusTone =
    | 'secondary'
    | 'warning'
    | 'info'
    | 'review'
    | 'approval'
    | 'approved'
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
    /** An executor company department, informational only; null when not given. */
    target_department: DepartmentOption | null;
    category: CategoryOption;
    /** The IC contact who made the request. */
    requester_name: string;
    /** The Unggul staff member IC contacted (optional, informational). */
    pic_name: string | null;
    /** The Admin WO who entered the work order. */
    entered_by: { name: string };
    created_at: string;
    /** Last activity: edits, comments, status changes, and attachments. */
    updated_at: string;
    deleted_at: string | null;
};

export type WorkOrderListItem = WorkOrder & {
    can: { update: boolean; delete: boolean; restore: boolean };
    /** In Pelaksanaan without today's daily report, after the cutoff (FLOW.md §7). */
    missing_daily_report: boolean;
};

/** A status change this user may perform (FLOW.md §5.1, §5.2). */
export type WorkOrderTransition = {
    value: string;
    /** Button label, e.g. "Ajukan", or "Ajukan ulang" after a rejection. */
    label: string;
    /** Rejecting, returning for revision, or cancelling: a destructive button. */
    destructive: boolean;
    requires_note: boolean;
    /** E.g. "Alasan penolakan"; "Catatan" when nothing more specific fits. */
    note_label: string;
    /** Why the button is disabled, e.g. no daily report yet; null when it may run. */
    blocked_reason: string | null;
};

/**
 * The payment track of a closed work order (FLOW.md §10): belum_ditagih,
 * ditagih, or lunas. Not a status of the work order itself.
 */
export type PaymentStatusOption = {
    value: 'belum_ditagih' | 'ditagih' | 'lunas';
    label: string;
    tone: string;
};

/** The invoice of a closed work order (FLOW.md §10), once Finance billed it. */
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

/** The reason given when the work order was rejected, returned for revision, or cancelled. */
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
    /** On the creation entry: the IC contact the work order was entered for. */
    on_behalf_of: string | null;
    created_at: string;
};

export type CommentEntry = {
    type: 'comment';
    id: number;
    user: { id: number; name: string };
    /**
     * HTML sanitized by the server (CommentHtml::forDisplay); null once
     * deleted. Render only through CommentBody.vue.
     */
    body: string | null;
    /** Documents listed below the body; empty once deleted. */
    attachments: Attachment[];
    deleted: boolean;
    edited: boolean;
    created_at: string;
    can: { update: boolean; delete: boolean };
};

/** One event of a work order's timeline, oldest first. */
export type TimelineEntry = StatusHistoryEntry | CommentEntry;

export type WorkOrderCommentSettings = {
    /** Plain-text characters per comment. */
    max_length: number;
    max_images: number;
    max_documents: number;
    /** Rules of the files uploaded while a comment is written. */
    uploads: { gambar: AttachmentRules; lampiran: AttachmentRules };
    /** The work order no longer accepts comments (Dibatalkan, or Closed and paid). */
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
    requester_name: string;
    status: WorkOrderStatusOption;
    /** ISO moment of the first submission. */
    submitted_at: string | null;
};

/** A daily progress report during Pelaksanaan (FLOW.md §7). */
export type WorkOrderDailyReport = {
    id: number;
    /** Calendar date (Y-m-d); format with formatCalendarDate. */
    report_date: string;
    note: string;
    /** http(s) links, validated by the server; render with safeHttpUrl(). */
    links: string[];
    files: Attachment[];
    reporter: { name: string };
    editor: { name: string } | null;
    created_at: string;
    updated_at: string | null;
    can_edit: boolean;
};

/** One working day of the report strip, oldest first. */
export type WorkOrderDailyReportDay = {
    date: string;
    state: 'reported' | 'missing' | 'pending' | 'not_required';
};

/** What the report form needs (DailyReportPanel::settings()). */
export type WorkOrderDailyReportSettings = {
    /** Calendar dates (Y-m-d) a new report may be dated between. */
    today: string;
    earliest_date: string;
    note_max_length: number;
    max_links: number;
    link_max_length: number;
    /** Links must be on one of these domains or a subdomain; any when empty. */
    link_domains: string[];
    files: AttachmentRules;
};
