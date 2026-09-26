import { describe, expect, it } from 'vite-plus/test';
import {
    calendarDateIn,
    formatCalendarDate,
    formatCalendarDateRange,
    formatDate,
    formatDateTime,
    formatFileSize,
    formatRelative,
    formatShortCalendarDate,
} from '@/lib/format';

const WITA = 'Asia/Makassar';

describe('formatDateTime', () => {
    it('converts a UTC moment to the display timezone', () => {
        expect(formatDateTime('2026-09-25T02:15:00+00:00', WITA)).toBe(
            '25 Sep 2026 10:15',
        );
    });

    it('rolls over to the next day and uses Indonesian month names', () => {
        expect(formatDateTime('2026-08-02T23:30:00Z', WITA)).toBe(
            '3 Agu 2026 07:30',
        );
    });

    it('follows the given timezone', () => {
        expect(formatDateTime('2026-09-25T03:15:00Z', 'Asia/Jakarta')).toBe(
            '25 Sep 2026 10:15',
        );
    });
});

describe('formatDate', () => {
    it('drops the time', () => {
        expect(formatDate('2026-05-31T16:00:00Z', WITA)).toBe('1 Jun 2026');
    });
});

describe('formatCalendarDate', () => {
    it('shows the calendar day without shifting it across timezones', () => {
        expect(formatCalendarDate('2026-08-31')).toBe('31 Agu 2026');
        expect(formatCalendarDate('2026-01-01')).toBe('1 Jan 2026');
    });
});

describe('formatFileSize', () => {
    it('writes sizes in binary units with an Indonesian decimal comma, like the server', () => {
        expect(formatFileSize(812)).toBe('812 B');
        expect(formatFileSize(1633)).toBe('1,6 KB');
        expect(formatFileSize(10 * 1024 * 1024)).toBe('10 MB');
        expect(formatFileSize(1258291)).toBe('1,2 MB');
    });
});

describe('formatShortCalendarDate', () => {
    it('shows the calendar day and month without shifting it', () => {
        expect(formatShortCalendarDate('2026-08-31')).toBe('31 Agu');
        expect(formatShortCalendarDate('2026-01-01')).toBe('1 Jan');
    });
});

describe('formatRelative', () => {
    const now = new Date('2026-09-25T12:00:00Z');

    it('says "baru saja" under a minute', () => {
        expect(formatRelative('2026-09-25T11:59:30Z', now)).toBe('baru saja');
    });

    it('uses the largest whole unit in Indonesian', () => {
        expect(formatRelative('2026-09-25T11:55:00Z', now)).toBe(
            '5 menit yang lalu',
        );
        expect(formatRelative('2026-09-25T09:00:00Z', now)).toBe(
            '3 jam yang lalu',
        );
        expect(formatRelative('2026-09-24T11:00:00Z', now)).toBe(
            '1 hari yang lalu',
        );
        expect(formatRelative('2026-07-20T12:00:00Z', now)).toBe(
            '2 bulan yang lalu',
        );
    });
});

describe('calendarDateIn', () => {
    it('names the day in the display timezone, not in UTC', () => {
        expect(calendarDateIn(new Date('2026-09-30T17:30:00Z'), WITA)).toBe(
            '2026-10-01',
        );
    });

    it('keeps the same day before midnight in the display timezone', () => {
        expect(calendarDateIn(new Date('2026-09-30T15:59:00Z'), WITA)).toBe(
            '2026-09-30',
        );
    });
});

describe('formatCalendarDateRange', () => {
    it('names both ends of a range', () => {
        expect(formatCalendarDateRange('2026-09-01', '2026-09-27')).toBe(
            '1 Sep 2026 – 27 Sep 2026',
        );
    });

    it('names a range open on one side', () => {
        expect(formatCalendarDateRange('2026-09-01', '')).toBe(
            'Sejak 1 Sep 2026',
        );
        expect(formatCalendarDateRange('', '2026-09-27')).toBe(
            'Sampai 27 Sep 2026',
        );
    });

    it('is empty without either end', () => {
        expect(formatCalendarDateRange('', '')).toBe('');
    });
});
