import { describe, expect, it } from 'vite-plus/test';
import { statusToneClass } from '@/lib/workOrderStatus';

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
