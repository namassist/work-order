import { describe, expect, it } from 'vite-plus/test';
import {
    formatCalendarDate,
    formatDate,
    formatDateTime,
    formatFileSize,
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
