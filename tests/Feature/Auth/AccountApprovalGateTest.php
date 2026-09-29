<?php

use App\Enums\Permission;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

dataset('accounts under review', [
    'pending' => fn () => User::factory()->pending()->create(),
    'rejected' => fn () => User::factory()->rejected()->create(),
]);

it('runs every signed-in route through the web group, where the gate is', function () {
    $unguarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => collect($route->gatherMiddleware())->contains(fn (string $middleware): bool => Str::startsWith($middleware, 'auth')))
        ->reject(fn (RoutingRoute $route): bool => in_array('web', $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route): string => $route->uri())
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});

it('redirects every other route to the status page, even with permissions', function (User $user, string $method, Closure $url) {
    $this->seed(RolePermissionSeeder::class);
    $user->givePermissionTo(Permission::values());
    $workOrder = WorkOrder::factory()->create(['requester_department_id' => $user->department_id]);

    $this->actingAs($user)
        ->call($method, $url($workOrder))
        ->assertRedirect(route('registration.status'));
})->with('accounts under review')->with([
    'dashboard' => ['GET', fn (): string => route('dashboard')],
    'work order list' => ['GET', fn (): string => route('work-orders.index')],
    'work order create' => ['GET', fn (): string => route('work-orders.create')],
    'work order store' => ['POST', fn (): string => route('work-orders.store')],
    'work order detail' => ['GET', fn (WorkOrder $workOrder): string => route('work-orders.show', $workOrder)],
    'work order export' => ['GET', fn (): string => route('work-orders.export')],
    'profile' => ['GET', fn (): string => route('profile.edit')],
    'profile update' => ['PATCH', fn (): string => route('profile.update')],
    'security' => ['GET', fn (): string => route('security.edit')],
    'password update' => ['PUT', fn (): string => route('user-password.update')],
    'appearance' => ['GET', fn (): string => route('appearance.edit')],
    'admin users' => ['GET', fn (): string => route('admin.users.index')],
    'admin registrations' => ['GET', fn (): string => route('admin.registrations.index')],
    'home' => ['GET', fn (): string => route('home')],
]);

it('shows a pending account its status page', function () {
    $user = User::factory()->pending()->create(['name' => 'Rani']);

    $this->actingAs($user)->get(route('registration.status'))->assertOk()->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->component('auth/RegistrationStatus')
        ->where('registration.status', 'pending')
        ->where('registration.name', 'Rani')
        ->where('registration.department', $user->department->name)
        ->where('registration.company', $user->department->company->name)
        ->where('registration.rejection_reason', null));
});

it('shows a rejected account the reason', function () {
    $user = User::factory()->rejected('Email bukan milik karyawan.')->create();

    $this->actingAs($user)->get(route('registration.status'))->assertOk()->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('registration.status', 'rejected')
        ->where('registration.rejection_reason', 'Email bukan milik karyawan.'));
});

it('lets an account under review log out', function (User $user) {
    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
})->with('accounts under review');

it('lets an account under review sign in, then keeps it on the status page', function (User $user) {
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertRedirect(route('registration.status'));
})->with('accounts under review');

it('sends an approved account from the status page to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('registration.status'))
        ->assertRedirect(route('dashboard'));
});

it('sends a guest from the status page to login', function () {
    $this->get(route('registration.status'))->assertRedirect(route('login'));
});
