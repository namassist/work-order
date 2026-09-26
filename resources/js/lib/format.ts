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
