<?php

use App\Models\User;

it('sends guests to the login page', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

it('sends signed-in users to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
