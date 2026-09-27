<?php

namespace App\Actions\Registrations;

use App\Enums\AccountStatus;
use App\Models\User;
use RuntimeException;

/**
 * A review the registration's current state no longer allows, usually because
 * another admin reviewed it since the page loaded. The message is shown to
 * the user.
 */
class RegistrationNotReviewable extends RuntimeException
{
    public static function alreadyReviewed(User $user): self
    {
        return new self(__('Pendaftaran :name sudah :status.', [
            'name' => $user->name,
            'status' => mb_strtolower($user->account_status->label()),
        ]));
    }

    public static function notPending(User $user): self
    {
        return $user->account_status === AccountStatus::Approved
            ? self::alreadyReviewed($user)
            : new self(__('Pendaftaran :name sudah ditolak; setujui jika ingin meninjau ulang.', ['name' => $user->name]));
    }
}
