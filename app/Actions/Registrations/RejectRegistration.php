<?php

namespace App\Actions\Registrations;

use App\Concerns\LogsAuditChanges;
use App\Enums\AccountStatus;
use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Rejects a pending registration with a reason, which the account sees on
 * its status page (FLOW.md §3), and logs one "registration_rejected" entry.
 */
class RejectRegistration
{
    use LogsAuditChanges;

    /**
     * @throws RegistrationNotReviewable when the registration is no longer pending
     */
    public function handle(User $registration, User $reviewer, string $reason): User
    {
        return DB::transaction(function () use ($registration, $reviewer, $reason): User {
            $user = User::query()->lockForUpdate()->findOrFail($registration->id);

            if ($user->account_status !== AccountStatus::Pending) {
                throw RegistrationNotReviewable::notPending($user);
            }

            $before = ['account_status' => $user->account_status->value, 'rejection_reason' => null];

            $user->disableLogging()->forceFill([
                'account_status' => AccountStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();
            $user->enableLogging();

            $this->logAuditChange($user, AuditEvent::RegistrationRejected, $before, [
                'account_status' => AccountStatus::Rejected->value,
                'rejection_reason' => $reason,
            ]);

            return $user;
        });
    }
}
