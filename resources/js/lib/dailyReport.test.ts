import { describe, expect, it } from 'vite-plus/test';
import { dayStateLabel, linkLabel, safeHttpUrl } from '@/lib/dailyReport';

describe('safeHttpUrl', () => {
    it('keeps absolute http and https links', () => {
        expect(
            safeHttpUrl('https://unggul.sharepoint.com/sites/wo/x.xlsx'),
        ).toBe('https://unggul.sharepoint.com/sites/wo/x.xlsx');
        expect(safeHttpUrl('http://intranet.worder.test/a')).toBe(
            'http://intranet.worder.test/a',
        );
    });

    it('refuses anything else, even if the server let it through', () => {
        for (const url of [
            'javascript:alert(1)',
            ' javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
            '/work-orders/1',
            '//evil.example.com',
            'not a url',
            '',
        ]) {
            expect(safeHttpUrl(url), url).toBeNull();
        }
    });
});

describe('linkLabel', () => {
    it('shows the host and the end of the path, decoded', () => {
        expect(
            linkLabel(
                'https://unggulgroup.sharepoint.com/sites/Operasional/Shared%20Documents/Timesheet/WO-PRD-2026-09-0001/Timesheet_20260929.xlsx',
            ),
        ).toBe('unggulgroup.sharepoint.com/…/Timesheet_20260929.xlsx');
    });

    it('shows a short link whole, without its scheme', () => {
        expect(linkLabel('https://1drv.ms/x/s!Ag123')).toBe(
            '1drv.ms/x/s!Ag123',
        );
        expect(linkLabel('https://example.com/')).toBe('example.com');
    });
});

describe('dayStateLabel', () => {
    it('names every state of the strip', () => {
        expect(dayStateLabel('reported')).toBe('Sudah lapor');
        expect(dayStateLabel('missing')).toBe('Belum lapor');
        expect(dayStateLabel('pending')).toBe('Hari ini, belum lapor');
        expect(dayStateLabel('not_required')).toBe('Tidak wajib');
    });
});
