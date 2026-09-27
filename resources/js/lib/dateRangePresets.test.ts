import { describe, expect, it } from 'vite-plus/test';
import { DATE_RANGE_PRESETS, presetRange } from '@/lib/dateRangePresets';

const WITA = 'Asia/Makassar';

/** 01:30 WITA on 1 Oct 2026, still 30 Sep in UTC. */
const earlyOctoberInWita = new Date('2026-09-30T17:30:00Z');

describe('presetRange', () => {
    it('takes "today" from the display timezone', () => {
        expect(presetRange('today', earlyOctoberInWita, WITA)).toEqual({
            from: '2026-10-01',
            to: '2026-10-01',
        });
    });

    it('counts today as one of the last 7 and 30 days', () => {
        expect(presetRange('last7Days', earlyOctoberInWita, WITA)).toEqual({
            from: '2026-09-25',
            to: '2026-10-01',
        });
        expect(presetRange('last30Days', earlyOctoberInWita, WITA)).toEqual({
            from: '2026-09-02',
            to: '2026-10-01',
        });
    });

    it('starts "this month" on the first day of the WITA month', () => {
        expect(presetRange('thisMonth', earlyOctoberInWita, WITA)).toEqual({
            from: '2026-10-01',
            to: '2026-10-01',
        });
        expect(
            presetRange('thisMonth', new Date('2026-09-27T04:00:00Z'), WITA),
        ).toEqual({ from: '2026-09-01', to: '2026-09-27' });
    });

    it('crosses a year boundary', () => {
        expect(
            presetRange('last7Days', new Date('2027-01-03T02:00:00Z'), WITA),
        ).toEqual({ from: '2026-12-28', to: '2027-01-03' });
    });
});

describe('DATE_RANGE_PRESETS', () => {
    it('lists the presets in Indonesian, shortest range first', () => {
        expect(DATE_RANGE_PRESETS.map((preset) => preset.label)).toEqual([
            'Hari ini',
            '7 hari terakhir',
            '30 hari terakhir',
            'Bulan ini',
        ]);
    });
});
