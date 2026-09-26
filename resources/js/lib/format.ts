/**
 * Date display for the whole app: stored UTC timestamps (ISO strings from the
 * server) shown in the display timezone as "25 Sep 2026 10:15". Matches
 * App\Support\DisplayDate on the server. In components use useFormatDate(),
 * which supplies the shared display timezone.
 */

const formatters = new Map<string, Intl.DateTimeFormat>();

function formatter(timeZone: string): Intl.DateTimeFormat {
    let cached = formatters.get(timeZone);

    if (cached === undefined) {
        cached = new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
            timeZone,
        });
        formatters.set(timeZone, cached);
    }

    return cached;
}

/**
 * id-ID writes "25 Sep 2026, 10.15"; take the parts and join them ourselves.
 */
function parts(iso: string, timeZone: string): Record<string, string> {
    return Object.fromEntries(
        formatter(timeZone)
            .formatToParts(new Date(iso))
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );
}

export function formatDateTime(iso: string, timeZone: string): string {
    const { day, month, year, hour, minute } = parts(iso, timeZone);

    return `${day} ${month} ${year} ${hour}:${minute}`;
}

export function formatDate(iso: string, timeZone: string): string {
    const { day, month, year } = parts(iso, timeZone);

    return `${day} ${month} ${year}`;
}

/**
 * A date-only value (Y-m-d, e.g. a WO target date) as "25 Sep 2026". It names
 * a calendar day, not a moment, so it is never timezone-converted.
 */
export function formatCalendarDate(date: string): string {
    return formatDate(`${date}T00:00:00Z`, 'UTC');
}

/**
 * A calendar-day range (Y-m-d ends, either may be empty) as
 * "1 Sep 2026 – 27 Sep 2026", "Sejak 1 Sep 2026", or "Sampai 27 Sep 2026".
 */
export function formatCalendarDateRange(from: string, to: string): string {
    if (from && to) {
        return `${formatCalendarDate(from)} – ${formatCalendarDate(to)}`;
    }

    if (from) {
        return `Sejak ${formatCalendarDate(from)}`;
    }

    return to ? `Sampai ${formatCalendarDate(to)}` : '';
}

/**
 * A date-only value (Y-m-d) as "25 Sep", for chart axes where the year is
 * implied. Like formatCalendarDate, never timezone-converted.
 */
export function formatShortCalendarDate(date: string): string {
    return formatCalendarDate(date).split(' ').slice(0, 2).join(' ');
}

const calendarDateFormatters = new Map<string, Intl.DateTimeFormat>();

/**
 * The calendar day (Y-m-d) a moment falls on in the given timezone, e.g. the
 * WITA "today" that date filters count from.
 */
export function calendarDateIn(moment: Date, timeZone: string): string {
    let cached = calendarDateFormatters.get(timeZone);

    if (cached === undefined) {
        cached = new Intl.DateTimeFormat('en-CA', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            timeZone,
        });
        calendarDateFormatters.set(timeZone, cached);
    }

    const { year, month, day } = Object.fromEntries(
        cached
            .formatToParts(moment)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );

    return `${year}-${month}-${day}`;
}

const relativeTime = new Intl.RelativeTimeFormat('id-ID', {
    numeric: 'always',
});

/** Largest unit first; each with its length in seconds. */
const RELATIVE_UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 60 * 60],
    ['month', 30 * 24 * 60 * 60],
    ['day', 24 * 60 * 60],
    ['hour', 60 * 60],
    ['minute', 60],
];

/**
 * How long ago a moment was, e.g. "5 menit yang lalu", in the largest whole
 * unit; "baru saja" under a minute. Show the full date next to it (e.g. on
 * hover), since this drops the precision.
 */
export function formatRelative(iso: string, now: Date): string {
    const seconds = Math.max(
        0,
        Math.floor((now.getTime() - new Date(iso).getTime()) / 1000),
    );

    for (const [unit, length] of RELATIVE_UNITS) {
        if (seconds >= length) {
            return relativeTime.format(-Math.floor(seconds / length), unit);
        }
    }

    return 'baru saja';
}

const FILE_SIZE_UNITS = ['B', 'KB', 'MB', 'GB'];
const fileSizeNumber = new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 1,
});

/**
 * A byte count as "1,6 KB" (1024-based, at most one decimal). Matches the
 * sizes the server writes in the activity log (Number::fileSize, locale id).
 */
export function formatFileSize(bytes: number): string {
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < FILE_SIZE_UNITS.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${fileSizeNumber.format(value)} ${FILE_SIZE_UNITS[unit]}`;
}
