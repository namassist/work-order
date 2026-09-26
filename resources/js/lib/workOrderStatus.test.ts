import { describe, expect, it } from 'vite-plus/test';
import { statusToneChartColor, statusToneClass } from '@/lib/workOrderStatus';

describe('statusToneClass', () => {
    it('maps each design-system tone to its token classes', () => {
        expect(statusToneClass('warning')).toBe(
            'bg-warning text-warning-foreground',
        );
        expect(statusToneClass('destructive')).toBe(
            'bg-destructive/10 text-destructive',
        );
    });

    it('falls back to the neutral tone for a tone it does not know', () => {
        expect(statusToneClass('ungu')).toBe(
            'bg-secondary text-secondary-foreground',
        );
    });
});

describe('statusToneChartColor', () => {
    it('fills chart series with the badge token of their tone', () => {
        expect(statusToneChartColor('destructive')).toBe('var(--destructive)');
    });

    it('uses chart tokens where the badge colour is under 3:1 as a fill', () => {
        expect(statusToneChartColor('warning')).toBe('var(--chart-submitted)');
    });

    it('fills the neutral tone with --chart-5, since the badge grey is too faint as a fill', () => {
        expect(statusToneChartColor('secondary')).toBe('var(--chart-5)');
        expect(statusToneChartColor('ungu')).toBe('var(--chart-5)');
    });
});
