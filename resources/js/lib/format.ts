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
