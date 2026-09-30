import type { WorkOrderDailyReportDay } from '@/types';

/**
 * The link as an href, only when it is an absolute http(s) URL; null for
 * anything else. The server validates links already (DailyReportLink); this
 * keeps a bad stored value from ever becoming a javascript: or data: href.
 */
export function safeHttpUrl(url: string): string | null {
    let parsed: URL;

    try {
        parsed = new URL(url);
    } catch {
        return null;
    }

    return (parsed.protocol === 'https:' || parsed.protocol === 'http:') &&
        parsed.host !== '' &&
        url.trim() === url
        ? url
        : null;
}

/**
 * A readable label for a link: its host and the last part of its path,
 * e.g. "unggulgroup.sharepoint.com/…/Timesheet_20260929.xlsx".
 */
export function linkLabel(url: string): string {
    let parsed: URL;

    try {
        parsed = new URL(url);
    } catch {
        return url;
    }

    const path = decodeSafely(`${parsed.pathname}${parsed.search}`).replace(
        /\/$/,
        '',
    );
    const segments = path.split('/').filter((segment) => segment !== '');

    if (segments.length <= 3 && path.length <= 40) {
        return `${parsed.host}${path}`;
    }

    return `${parsed.host}/…/${segments[segments.length - 1]}`;
}

const DAY_STATE_LABELS: Record<WorkOrderDailyReportDay['state'], string> = {
    reported: 'Sudah lapor',
    missing: 'Belum lapor',
    pending: 'Hari ini, belum lapor',
    not_required: 'Tidak wajib',
};

/** The strip's day states, as read by screen readers and in tooltips. */
export function dayStateLabel(state: WorkOrderDailyReportDay['state']): string {
    return DAY_STATE_LABELS[state];
}

function decodeSafely(value: string): string {
    try {
        return decodeURIComponent(value);
    } catch {
        return value;
    }
}
