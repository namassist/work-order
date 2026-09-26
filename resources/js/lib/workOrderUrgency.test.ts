import { ChevronDown, ChevronsUp, ChevronUp, Minus } from '@lucide/vue';
import { describe, expect, it } from 'vite-plus/test';
import { isUrgent, urgencyIcon } from '@/lib/workOrderUrgency';

describe('urgencyIcon', () => {
    it('gives each urgency its own icon', () => {
        expect(urgencyIcon('rendah')).toBe(ChevronDown);
        expect(urgencyIcon('normal')).toBe(Minus);
        expect(urgencyIcon('tinggi')).toBe(ChevronUp);
        expect(urgencyIcon('mendesak')).toBe(ChevronsUp);
    });

    it('falls back to the normal icon for an urgency it does not know', () => {
        expect(urgencyIcon('kritis')).toBe(Minus);
    });
});

describe('isUrgent', () => {
    it('emphasises only mendesak', () => {
        expect(
            ['rendah', 'normal', 'tinggi', 'mendesak'].filter(isUrgent),
        ).toEqual(['mendesak']);
    });
});
