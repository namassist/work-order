<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the greeting shows the user\'s department, even a deleted one', function () {
    $department = Department::factory()->create(['code' => 'FIN', 'name' => 'Keuangan']);
    $user = User::factory()->for($department)->create();
    $department->delete();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->component('Dashboard')
            ->where('department', ['code' => 'FIN', 'name' => 'Keuangan']));
});

test('a user without a department gets none', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('department', null));
});

test('activity-log viewers get the latest activity as a deferred prop', function () {
    $this->travelTo(Carbon::parse('2026-09-01', 'UTC'));
    $auditor = userWithPermissions(Permission::ActivityLogView);
    foreach (range(1, 10) as $minute) {
        activity()->createdAt(Carbon::parse("2026-09-25 08:{$minute}", 'UTC'))->event('updated')->log("entry {$minute}");
    }

    $this->actingAs($auditor)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->missing('recentActivities')
            ->loadDeferredProps(fn (Assert $reload): AssertableInertia => $reload
                ->count('recentActivities', 8)
                ->where('recentActivities.0.created_at', '2026-09-25T08:10:00+00:00')));
});

test('users who cannot view the activity log get no activity', function () {
    activity()->event('updated')->log('updated');

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('recentActivities', null));
});
