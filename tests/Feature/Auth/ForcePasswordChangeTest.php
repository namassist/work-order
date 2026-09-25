<?php

use App\Models\User;
use Illuminate\Support\Facades\Password;

it('sends a user who must change their password to the security page', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

it('still lets that user confirm their password, open the security page, and log out', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->get(route('password.confirm'))->assertOk();
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk();
    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));
});

it('clears the flag once the user changes their password', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->must_change_password)->toBeFalse();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('clears the flag when the password is reset through forgot password', function () {
    $user = User::factory()->mustChangePassword()->create();
    $token = Password::createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->must_change_password)->toBeFalse();
});

it('does not redirect users who already changed their password', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk();
});
