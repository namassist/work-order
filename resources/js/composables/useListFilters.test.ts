import { describe, expect, it } from 'vite-plus/test';
import { ALL, isFiltering, toFilterQuery } from '@/composables/useListFilters';

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

describe('isFiltering', () => {
    it('is false when every filter is at its "show everything" value', () => {
        expect(isFiltering({ search: '', status: ALL, trashed: false })).toBe(
            false,
        );
    });

    it('is true once any filter narrows the list', () => {
        expect(isFiltering({ search: '', status: ALL, trashed: true })).toBe(
            true,
        );
    });
});
