import { describe, expect, it } from 'vite-plus/test';
import { ALL, toFilterQuery } from '@/composables/useListFilters';

describe('toFilterQuery', () => {
    it('drops empty, unchecked, and "all" filters', () => {
        expect(
            toFilterQuery({ search: '', status: ALL, trashed: false }),
        ).toEqual({});
    });

    it('sends checked filters as 1 and keeps other values', () => {
        expect(
            toFilterQuery({ search: 'fin', status: 'active', trashed: true }),
        ).toEqual({ search: 'fin', status: 'active', trashed: 1 });
    });
});
