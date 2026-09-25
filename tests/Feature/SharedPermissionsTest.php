<?php

use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

it('shares the permissions granted through roles with the frontend', function () {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create()->assignRole('keuangan');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('auth.permissions', [
            Permission::DepartmentsView->value,
            Permission::WorkOrdersView->value,
        ]));
});
