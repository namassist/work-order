<?php

namespace App\Listeners;

use App\Concerns\LogsAuthActivity;
use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Records login, logout, failed login, and password reset in the "auth" log.
 * Registered by event discovery through the handle* methods.
 */
class RecordAuthActivity
{
    use LogsAuthActivity;

    /**
     * Record a successful login.
     */
    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->logAuthActivity(AuditEvent::Login, $event->user);
        }
    }

    /**
     * Record a logout.
     */
    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->logAuthActivity(AuditEvent::Logout, $event->user);
        }
    }

    /**
     * Record a failed login with the email tried. The event also carries the
     * password the visitor typed, which must never be read here.
     */
    public function handleFailed(Failed $event): void
    {
        $this->logFailedLogin($event->credentials['email'] ?? null);
    }

    /**
     * Record a password reset through the forgot-password link.
     */
    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->logAuthActivity(AuditEvent::PasswordReset, $event->user);
        }
    }
}
