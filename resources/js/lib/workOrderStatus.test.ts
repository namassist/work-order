import { describe, expect, it } from 'vite-plus/test';
import { statusChartColor, statusToneClass } from '@/lib/workOrderStatus';

describe('statusToneClass', () => {
    it('maps each design-system tone to its token classes', () => {
        expect(statusToneClass('warning')).toBe(
            'bg-warning text-warning-foreground',
        );
        expect(statusToneClass('destructive')).toBe(
            'bg-destructive/10 text-destructive',
        );
        expect(statusToneClass('muted')).toBe(
            'border-border bg-transparent text-muted-foreground',
        );
    });

    it('falls back to the neutral tone for a tone it does not know', () => {
        expect(statusToneClass('ungu')).toBe(
            'bg-secondary text-secondary-foreground',
        );
    });
});

describe('statusChartColor', () => {
    const status = (value: string, tone: string) => ({ value, tone });

    it('fills chart series with the badge token of their tone', () => {
        expect(statusChartColor(status('ditolak', 'destructive'))).toBe(
            'var(--destructive)',
        );
        expect(statusChartColor(status('dikerjakan', 'info'))).toBe(
            'var(--info)',
        );
    });

    it('uses chart tokens where the badge colour is under 3:1 as a fill', () => {
        expect(statusChartColor(status('diajukan', 'warning'))).toBe(
            'var(--chart-submitted)',
        );
    });

    it('gives Dibatalkan a dark neutral apart from Draft, whatever its tone', () => {
        expect(statusChartColor(status('dibatalkan', 'muted'))).toBe(
            'var(--chart-cancelled)',
        );
        expect(statusChartColor(status('dibatalkan', 'destructive'))).toBe(
            'var(--chart-cancelled)',
        );
        expect(statusChartColor(status('draft', 'secondary'))).toBe(
            'var(--chart-5)',
        );
    });

    it('falls back to --chart-5 for a tone it does not know', () => {
        expect(statusChartColor(status('baru', 'ungu'))).toBe('var(--chart-5)');
    });
});
