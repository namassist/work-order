import { describe, expect, it } from 'vite-plus/test';
import {
    companyAllowsEmail,
    companyForEmail,
    emailDomain,
} from '@/lib/registration';

const ic = { id: 1, name: 'IC', email_domains: ['ic.test'] };
const unggul = {
    id: 2,
    name: 'Unggul',
    email_domains: ['unggul.test', 'unggul.co.id'],
};

describe('emailDomain', () => {
    it('reads the lowercase domain after the last @', () => {
        expect(emailDomain('Rani@IC.Test')).toBe('ic.test');
        expect(emailDomain('"a@b"@ic.test')).toBe('ic.test');
    });

    it('is null while there is no domain yet', () => {
        expect(emailDomain('rani')).toBeNull();
        expect(emailDomain('rani@')).toBeNull();
    });
});

describe('companyAllowsEmail', () => {
    it('matches one of the domains exactly, ignoring case', () => {
        expect(companyAllowsEmail(unggul, 'budi@UNGGUL.CO.ID')).toBe(true);
    });

    it('does not match subdomains or lookalikes', () => {
        expect(companyAllowsEmail(ic, 'rani@mail.ic.test')).toBe(false);
        expect(companyAllowsEmail(ic, 'rani@ic.test.evil.test')).toBe(false);
        expect(companyAllowsEmail(ic, 'rani@xic.test')).toBe(false);
    });
});

describe('companyForEmail', () => {
    it('finds the company of the email domain', () => {
        expect(companyForEmail([ic, unggul], 'budi@unggul.test')).toBe(unggul);
    });

    it('is null for an unknown domain', () => {
        expect(companyForEmail([ic, unggul], 'rani@gmail.com')).toBeNull();
    });
});
