import type { ActivityValue } from '@/types';

const DESTRUCTIVE = 'bg-destructive/10 text-destructive';
const SUCCESS = 'bg-success text-success-foreground';
const INFO = 'bg-info text-info-foreground';
const NEUTRAL = 'bg-secondary text-secondary-foreground';

const EVENT_TONES: Record<string, string> = {
    created: SUCCESS,
    restored: SUCCESS,
    activated: SUCCESS,
    updated: INFO,
    roles_updated: INFO,
    password_initial_changed: INFO,
    password_changed: INFO,
    password_reset: INFO,
    deleted: DESTRUCTIVE,
    deactivated: DESTRUCTIVE,
    login_failed: DESTRUCTIVE,
};

/**
 * Badge classes for an activity event. The label is always shown too, so
 * colour is never the only signal.
 */
export function eventTone(event: string): string {
    return EVENT_TONES[event] ?? NEUTRAL;
}

/**
 * Items added to and removed from a list attribute such as roles.
 */
export function listDiff(
    old: string[] | null,
    current: string[] | null,
): { added: string[]; removed: string[] } {
    const before = old ?? [];
    const after = current ?? [];

    return {
        added: after.filter((item) => !before.includes(item)),
        removed: before.filter((item) => !after.includes(item)),
    };
}

export function isListValue(value: ActivityValue): value is string[] {
    return Array.isArray(value);
}

const PROPERTY_LABELS: Record<string, string> = {
    email: 'Email',
    ip: 'IP',
    reason: 'Alasan',
};

const REASON_LABELS: Record<string, string> = {
    inactive: 'Akun nonaktif',
};

/**
 * Labelled rows for the extra details of an entry (auth email, IP, reason).
 */
export function describeProperties(
    properties: Record<string, string>,
): { label: string; value: string }[] {
    return Object.entries(properties).map(([key, value]) => ({
        label: PROPERTY_LABELS[key] ?? key,
        value: key === 'reason' ? (REASON_LABELS[value] ?? value) : value,
    }));
}
