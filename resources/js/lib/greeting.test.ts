import { describe, expect, it } from 'vite-plus/test';
import { greeting } from '@/lib/greeting';

const WITA = 'Asia/Makassar';

/** A UTC instant that is the given WITA (UTC+8) wall-clock time on 25 Sep. */
const wita = (time: string): Date => new Date(`2026-09-25T${time}:00+08:00`);

describe('greeting', () => {
    it.each([
        ['03:59', 'Selamat malam'],
        ['04:00', 'Selamat pagi'],
        ['10:59', 'Selamat pagi'],
        ['11:00', 'Selamat siang'],
        ['14:59', 'Selamat siang'],
        ['15:00', 'Selamat sore'],
        ['17:59', 'Selamat sore'],
        ['18:00', 'Selamat malam'],
    ])('says the right thing at %s WITA', (time, expected) => {
        expect(greeting(wita(time), WITA)).toBe(expected);
    });

    it('reads the hour in the display timezone, not UTC', () => {
        // 23:30 UTC is 07:30 WITA.
        expect(greeting(new Date('2026-09-25T23:30:00Z'), WITA)).toBe(
            'Selamat pagi',
        );
    });
});
