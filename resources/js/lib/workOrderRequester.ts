import type { RequesterAccount } from '@/types';

export type RequesterMode = 'account' | 'contact';

/**
 * The requester fields the work order form sends (see
 * App\Concerns\WorkOrderRequesterRules): none unless the user chooses the
 * requester (on-behalf create, or correcting an on-behalf draft); then the
 * mode with exactly one of the account id or the contact name, and on
 * create also the IC department.
 */
export function requesterPayload(options: {
    choosesRequester: boolean;
    onBehalf: boolean;
    mode: RequesterMode;
    account: RequesterAccount | null;
    contactName: string;
    departmentId: number | null;
}): Record<string, string | number | null> {
    if (!options.choosesRequester) {
        return {};
    }

    return {
        requester_mode: options.mode,
        requester_id:
            options.mode === 'account' ? (options.account?.id ?? null) : null,
        requester_name: options.mode === 'contact' ? options.contactName : null,
        ...(options.onBehalf
            ? { requester_department_id: options.departmentId }
            : {}),
    };
}
