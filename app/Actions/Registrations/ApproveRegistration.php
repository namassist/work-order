<?php

namespace App\Actions\Registrations;

use App\Concerns\LogsAuditChanges;
use App\Enums\AccountStatus;
use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Approves a pending registration, or a rejected one on re-review (FLOW.md
 * §3): grants the chosen roles, optionally moves the account to another
 * department of the same company, and logs one "registration_approved" entry.
 * The caller validates the roles and the department (ApproveRegistrationRequest).
 */
class ApproveRegistration
{
    use LogsAuditChanges;

    /**
     * @param  list<string>  $roles
     *
     * @throws RegistrationNotReviewable when the registration was approved meanwhile
     */
    public function handle(User $registration, User $reviewer, array $roles, int $departmentId): User
    {
        return DB::transaction(function () use ($registration, $reviewer, $roles, $departmentId): User {
            $user = User::query()->lockForUpdate()->findOrFail($registration->id);

            if ($user->isApproved()) {
                throw RegistrationNotReviewable::alreadyReviewed($user);
            }

            $before = $this->reviewedState($user);

            $user->disableLogging()->forceFill([
                'department_id' => $departmentId,
                'account_status' => AccountStatus::Approved,
                'rejection_reason' => null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();
            $user->enableLogging();
            $user->syncRoles($roles);

            $this->logAuditChange($user, AuditEvent::RegistrationApproved, $before, $this->reviewedState($user));

            return $user;
        });
    }

    /**
     * @return array{account_status: string, department_id: int, rejection_reason: string|null, roles: list<string>}
     */
    private function reviewedState(User $user): array
    {
        return [
            'account_status' => $user->account_status->value,
            'department_id' => $user->department_id,
            'rejection_reason' => $user->rejection_reason,
            'roles' => array_values(Role::query()
                ->whereRelation('users', 'users.id', $user->id)
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role): string => $role->name)
                ->all()),
        ];
    }
}
