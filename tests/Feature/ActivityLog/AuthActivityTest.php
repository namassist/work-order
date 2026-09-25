<?php

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Spatie\Activitylog\Models\Activity;

/**
 * The only auth activity recorded so far.
 */
function soleAuthActivity(): Activity
{
    return Activity::query()->inLog('auth')->sole();
}

/**
 * Assert that no activity row contains the given secret anywhere.
 */
function assertSecretNotLogged(string $secret): void
{
    expect(Activity::all()->toJson())->not->toContain($secret);
}

describe('login and logout', function () {
    it('logs a successful login without a user update entry', function () {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => true]);

        expect(soleAuthActivity())
            ->event->toBe('login')
            ->subject_id->toBe($user->id)
            ->causer_id->toBe($user->id)
            ->and(soleAuthActivity()->properties->all())->toEqual(['ip' => '127.0.0.1']);
        expect(Activity::query()->forSubject($user)->pluck('event')->all())->toBe(['created', 'login']);
    });

    it('logs a logout', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'));

        expect(soleAuthActivity())
            ->event->toBe('logout')
            ->subject_id->toBe($user->id)
            ->causer_id->toBe($user->id);
    });
});

describe('failed login', function () {
    it('logs the email tried and the IP, but never the password', function () {
        $user = User::factory()->create(['email' => 'budi@example.com']);

        $this->post(route('login.store'), ['email' => 'budi@example.com', 'password' => 'tebakan-salah']);

        expect(soleAuthActivity())
            ->event->toBe('login_failed')
            ->subject_id->toBe($user->id)
            ->causer_id->toBeNull()
            ->and(soleAuthActivity()->properties->all())->toEqual(['email' => 'budi@example.com', 'ip' => '127.0.0.1']);
        assertSecretNotLogged('tebakan-salah');
    });

    it('logs an attempt on an unknown email without a subject', function () {
        $this->post(route('login.store'), ['email' => 'tidak-ada@example.com', 'password' => 'tebakan-salah']);

        expect(soleAuthActivity())
            ->event->toBe('login_failed')
            ->subject_id->toBeNull()
            ->and(soleAuthActivity()->getProperty('email'))->toBe('tidak-ada@example.com');
    });

    it('logs an attempt on a deactivated account with the reason', function () {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        expect(soleAuthActivity())
            ->event->toBe('login_failed')
            ->subject_id->toBe($user->id)
            ->and(soleAuthActivity()->properties->all())->toEqual(['email' => $user->email, 'ip' => '127.0.0.1', 'reason' => 'inactive']);
        assertSecretNotLogged('"password"');
    });
});

describe('password changes', function () {
    it('logs the first-login password change', function () {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'password-rahasia-baru',
            'password_confirmation' => 'password-rahasia-baru',
        ])->assertSessionHasNoErrors();

        expect(soleAuthActivity())
            ->event->toBe('password_initial_changed')
            ->subject_id->toBe($user->id)
            ->causer_id->toBe($user->id);
        assertSecretNotLogged('password-rahasia-baru');
    });

    it('logs a password change from settings', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'password-rahasia-baru',
            'password_confirmation' => 'password-rahasia-baru',
        ])->assertSessionHasNoErrors();

        expect(soleAuthActivity()->event)->toBe('password_changed');
        assertSecretNotLogged('password-rahasia-baru');
    });

    it('logs nothing when the current password is wrong', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'salah',
            'password' => 'password-rahasia-baru',
            'password_confirmation' => 'password-rahasia-baru',
        ])->assertSessionHasErrors('current_password');

        expect(Activity::query()->inLog('auth')->exists())->toBeFalse();
    });

    it('logs a forgot-password reset', function () {
        $user = User::factory()->mustChangePassword()->create();

        $this->post(route('password.update'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'password-rahasia-baru',
            'password_confirmation' => 'password-rahasia-baru',
        ])->assertSessionHasNoErrors();

        expect(soleAuthActivity())
            ->event->toBe('password_reset')
            ->subject_id->toBe($user->id)
            ->causer_id->toBe($user->id);
        assertSecretNotLogged('password-rahasia-baru');
    });
});
