import { describe, expect, it } from 'vite-plus/test';
import { formatDate, formatDateTime } from '@/lib/format';

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
