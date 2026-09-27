import { describe, expect, it } from 'vite-plus/test';
import { requesterPayload } from '@/lib/workOrderRequester';

const account = { id: 7, name: 'Eko Purnomo', email: 'eko@ic.test' };
const base = {
    choosesRequester: true,
    onBehalf: true,
    mode: 'account' as const,
    account,
    contactName: 'Pak Andi',
    departmentId: 3,
};

describe('requesterPayload', () => {
    it('sends nothing when the user does not choose the requester', () => {
        expect(requesterPayload({ ...base, choosesRequester: false })).toEqual(
            {},
        );
    });

    it('sends the account and the department on an on-behalf create', () => {
        expect(requesterPayload(base)).toEqual({
            requester_mode: 'account',
            requester_id: 7,
            requester_name: null,
            requester_department_id: 3,
        });
    });

    it('sends only the contact name in contact mode', () => {
        expect(requesterPayload({ ...base, mode: 'contact' })).toEqual({
            requester_mode: 'contact',
            requester_id: null,
            requester_name: 'Pak Andi',
            requester_department_id: 3,
        });
    });

    it('never sends the department when correcting a draft', () => {
        expect(requesterPayload({ ...base, onBehalf: false })).toEqual({
            requester_mode: 'account',
            requester_id: 7,
            requester_name: null,
        });
    });

    it('sends no account id while none is picked', () => {
        expect(
            requesterPayload({ ...base, account: null }).requester_id,
        ).toBeNull();
    });
});
