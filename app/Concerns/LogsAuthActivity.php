<?php

namespace App\Concerns;

use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Logs authentication events to the "auth" log with the request IP.
 * Callers pass only safe properties; password values are never logged.
 */
trait LogsAuthActivity
{
    /**
     * Log an event performed by the user on their own account.
     */
    protected function logAuthActivity(AuditEvent $event, User $user): void
    {
        activity('auth')
            ->event($event->value)
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties(['ip' => (string) request()->ip()])
            ->log($event->value);
    }

    /**
     * Log a refused login. The visitor is unknown, so there is no causer; the
     * account is the subject when the email tried belongs to one.
     */
    protected function logFailedLogin(mixed $email, ?string $reason = null): void
    {
        $email = is_string($email) ? Str::limit($email, 255, '') : '';
        $user = User::where('email', $email)->first();

        $logger = activity('auth')
            ->event(AuditEvent::LoginFailed->value)
            ->withProperties(array_filter([
                'email' => $email,
                'ip' => (string) request()->ip(),
                'reason' => $reason,
            ], fn (?string $value): bool => $value !== null));

        if ($user !== null) {
            $logger->performedOn($user);
        }

        $logger->log(AuditEvent::LoginFailed->value);
    }
}
