<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('rejects login for a deactivated user', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Akun Anda telah dinonaktifkan.']);

    $this->assertGuest();
});

it('rejects login for a soft-deleted user', function () {
    $user = User::factory()->create();
    $user->delete();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('ends the session of a user deactivated while logged in', function () {
    $user = User::factory()->inactive()->create();

    $this->withSession([Auth::guard('web')->getName() => $user->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Akun Anda telah dinonaktifkan.');

    $this->assertGuest();
});

it('ends the session of a user deleted while logged in', function () {
    $user = User::factory()->create();
    $user->delete();

    $this->withSession([Auth::guard('web')->getName() => $user->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
