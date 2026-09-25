<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\LogsAuthActivity;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    use LogsAuthActivity;

    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Update the user's password. Replacing the default password is logged
     * separately from a later change.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $wasDefaultPassword = $user->must_change_password;

        $user->update([
            'password' => $request->password,
            'must_change_password' => false,
        ]);

        $this->logAuthActivity($wasDefaultPassword ? AuditEvent::PasswordInitialChanged : AuditEvent::PasswordChanged, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password diperbarui.')]);

        return back();
    }
}
