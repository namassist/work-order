import { describe, expect, it } from 'vite-plus/test';
import { describeProperties, eventTone, listDiff } from '@/lib/activity';

describe('listDiff', () => {
    it('splits a list change into added and removed items', () => {
        expect(
            listDiff(['viewer', 'pemohon'], ['pemohon', 'keuangan']),
        ).toEqual({ added: ['keuangan'], removed: ['viewer'] });
    });

    it('treats a missing side as an empty list', () => {
        expect(listDiff(null, ['viewer'])).toEqual({
            added: ['viewer'],
            removed: [],
        });
        expect(listDiff(['viewer'], null)).toEqual({
            added: [],
            removed: ['viewer'],
        });
    });
});

describe('eventTone', () => {
    it('marks refusals and removals as destructive', () => {
        for (const event of ['deleted', 'deactivated', 'login_failed']) {
            expect(eventTone(event)).toContain('text-destructive');
        }
    });

    it('falls back to a neutral tone for unknown events', () => {
        expect(eventTone('something_new')).toContain('bg-secondary');
    });
});

describe('describeProperties', () => {
    it('labels auth details and translates the refusal reason', () => {
        expect(
            describeProperties({
                email: 'budi@example.com',
                ip: '10.0.0.8',
                reason: 'inactive',
            }),
        ).toEqual([
            { label: 'Email', value: 'budi@example.com' },
            { label: 'IP', value: '10.0.0.8' },
            { label: 'Alasan', value: 'Akun nonaktif' },
        ]);
    });

    it('labels export details', () => {
        expect(
            describeProperties({ filter: 'Status: Draft', jumlah_baris: '12' }),
        ).toEqual([
            { label: 'Filter', value: 'Status: Draft' },
            { label: 'Jumlah baris', value: '12' },
        ]);
    });

    it('shows unknown keys and reasons as they were logged', () => {
        expect(describeProperties({ source: 'import', reason: 'x' })).toEqual([
            { label: 'source', value: 'import' },
            { label: 'Alasan', value: 'x' },
        ]);
    });
});
