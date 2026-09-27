import { parseDate } from '@internationalized/date';
import { calendarDateIn } from '@/lib/format';

/** A date filter range as calendar days (Y-m-d), as the list query sends it. */
export type CalendarDateRange = { from: string; to: string };

export type DateRangePresetKey =
    | 'today'
    | 'last7Days'
    | 'last30Days'
    | 'thisMonth';

export const DATE_RANGE_PRESETS: {
    key: DateRangePresetKey;
    label: string;
}[] = [
    { key: 'today', label: 'Hari ini' },
    { key: 'last7Days', label: '7 hari terakhir' },
    { key: 'last30Days', label: '30 hari terakhir' },
    { key: 'thisMonth', label: 'Bulan ini' },
];

/**
 * The range a preset stands for, ending today in the display timezone. Both
 * ends are calendar days, which the server turns into UTC bounds.
 */
export function presetRange(
    key: DateRangePresetKey,
    now: Date,
    timeZone: string,
): CalendarDateRange {
    const today = parseDate(calendarDateIn(now, timeZone));
    const from = {
        today,
        last7Days: today.subtract({ days: 6 }),
        last30Days: today.subtract({ days: 29 }),
        thisMonth: today.set({ day: 1 }),
    }[key];

    return { from: from.toString(), to: today.toString() };
}
