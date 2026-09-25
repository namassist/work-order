import { describe, expect, it } from 'vite-plus/test';
import { getInitials } from './useInitials';

describe('getInitials', () => {
    it('returns an empty string when no name is given', () => {
        expect(getInitials()).toBe('');
        expect(getInitials('   ')).toBe('');
    });

    it('returns the uppercased first letter of a single name', () => {
        expect(getInitials('taylor')).toBe('T');
    });

    it('uses the first and last names only', () => {
        expect(getInitials('taylor alan otwell')).toBe('TO');
    });
});
