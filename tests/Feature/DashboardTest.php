<?php

use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Enums\Permission;
use App\Enums\WorkOrderUrgency;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\WorkOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

test('work order counts cover only the work orders the user may see', function () {
    $department = Department::factory()->create();
    WorkOrder::factory()->create(['department_id' => $department->id]);
    WorkOrder::factory()->submitted()->create(['department_id' => $department->id]);
    WorkOrder::factory()->cancelled()->create(['department_id' => $department->id]);
    WorkOrder::factory()->submitted()->create();
    WorkOrder::factory()->create(['department_id' => $department->id])->delete();

    $this->actingAs(userInDepartment($department, Permission::WorkOrdersView))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->missing('workOrderCounts')
            ->loadDeferredProps(fn (Assert $reload): AssertableInertia => $reload
                ->where('workOrderCounts', ['total' => 3, 'pending' => 1, 'overdue' => 0])));
});

test('work order counts span every department with work-orders.view-all', function () {
    WorkOrder::factory()->submitted()->count(2)->create();

    $this->actingAs(userWithPermissions(Permission::WorkOrdersView, Permission::WorkOrdersViewAll))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->loadDeferredProps(fn (Assert $reload): AssertableInertia => $reload
                ->where('workOrderCounts', ['total' => 2, 'pending' => 2, 'overdue' => 0])));
});

test('users who cannot list work orders get no counts', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->where('workOrderCounts', null)
            ->where('requestOverview', null)
            ->where('urgentWorkOrders', null));
});

test('terlambat counts visible submitted work orders whose target date is before today in WITA', function () {
    // 07:30 WITA on 25 Sep, still 24 Sep in UTC.
    $this->travelTo(Carbon::parse('2026-09-24 23:30', 'UTC'));
    $department = Department::factory()->create();
    $inDepartment = ['department_id' => $department->id];

    WorkOrder::factory()->submitted()->create([...$inDepartment, 'target_date' => '2026-09-24']);
    WorkOrder::factory()->submitted()->create([...$inDepartment, 'target_date' => '2026-09-25']);
    WorkOrder::factory()->submitted()->create([...$inDepartment, 'target_date' => null]);
    WorkOrder::factory()->create([...$inDepartment, 'target_date' => '2026-09-01']);
    WorkOrder::factory()->cancelled()->create([...$inDepartment, 'target_date' => '2026-09-01']);
    WorkOrder::factory()->submitted()->create(['target_date' => '2026-09-01']);

    $this->actingAs(userInDepartment($department, Permission::WorkOrdersView))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->loadDeferredProps(fn (Assert $reload): AssertableInertia => $reload
                ->where('workOrderCounts.overdue', 1)));
});

test('the request overview counts visible work orders per WITA day and current status', function () {
    // 20:00 WITA on 25 Sep.
    $this->travelTo(Carbon::parse('2026-09-25 12:00', 'UTC'));
    $department = Department::factory()->create();
    $inDepartment = ['department_id' => $department->id];

    // 07:30 WITA on 25 Sep.
    WorkOrder::factory()->create([...$inDepartment, 'created_at' => Carbon::parse('2026-09-24 23:30', 'UTC')]);
    // 23:59 WITA on 24 Sep.
    WorkOrder::factory()->submitted()->create([...$inDepartment, 'created_at' => Carbon::parse('2026-09-24 15:59', 'UTC')]);
    WorkOrder::factory()->cancelled()->create([...$inDepartment, 'created_at' => Carbon::parse('2026-09-25 01:00', 'UTC')]);
    WorkOrder::factory()->create(['created_at' => Carbon::parse('2026-09-25 01:00', 'UTC')]);
    WorkOrder::factory()->create([...$inDepartment, 'created_at' => Carbon::parse('2026-09-25 01:00', 'UTC')])->delete();
    // 23:59 WITA on 18 Sep, the day before the period.
    WorkOrder::factory()->create([...$inDepartment, 'created_at' => Carbon::parse('2026-09-18 15:59', 'UTC')]);

    $none = ['draft' => 0, 'diajukan' => 0, 'dibatalkan' => 0];

    $this->actingAs(userInDepartment($department, Permission::WorkOrdersView))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->missing('requestOverview')
            ->loadDeferredProps(fn (Assert $reload): AssertableInertia => $reload
                ->where('requestOverview.days', 7)
                ->where('requestOverview.statuses', WorkOrderStatus::options())
                ->where('requestOverview.series', [
                    ['date' => '2026-09-19', 'counts' => $none],
                    ['date' => '2026-09-20', 'counts' => $none],
                    ['date' => '2026-09-21', 'counts' => $none],
                    ['date' => '2026-09-22', 'counts' => $none],
                    ['date' => '2026-09-23', 'counts' => $none],
                    ['date' => '2026-09-24', 'counts' => [...$none, 'diajukan' => 1]],
                    ['date' => '2026-09-25', 'counts' => [...$none, 'draft' => 1, 'dibatalkan' => 1]],
                ])));
});

test('the request overview covers the last 30 days when asked', function () {
    $this->travelTo(Carbon::parse('2026-09-25 12:00', 'UTC'));
    $department = Department::factory()->create();
    WorkOrder::factory()->create(['department_id' => $department->id, 'created_at' => Carbon::parse('2026-09-05 12:00', 'UTC')]);

    $this->actingAs(userInDepartment($department, Permission::WorkOrdersView))
        ->get(route('dashboard', ['period' => 30]))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->loadDeferredProps(fn (Assert $reload): AssertableInertia => $reload
                ->where('requestOverview.days', 30)
                ->count('requestOverview.series', 30)
                ->where('requestOverview.series.0.date', '2026-08-27')
                ->where('requestOverview.series.9', ['date' => '2026-09-05', 'counts' => ['draft' => 1, 'diajukan' => 0, 'dibatalkan' => 0]])));
});

test('the request overview falls back to 7 days for an unknown period', function (string $period) {
    $this->actingAs(userWithPermissions(Permission::WorkOrdersView))
        ->get(route('dashboard', ['period' => $period]))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->loadDeferredProps(fn (Assert $reload): AssertableInertia => $reload
                ->where('requestOverview.days', 7)
                ->count('requestOverview.series', 7)));
})->with(['14', 'abc', '']);

/**
 * A work order submitted at the given UTC moment through the real transition.
 *
 * @param  array<string, mixed>  $attributes
 */
function workOrderSubmittedAt(string $moment, array $attributes): WorkOrder
{
    test()->travelTo(Carbon::parse($moment, 'UTC'));
    $workOrder = WorkOrder::factory()->create($attributes);

    return app(TransitionWorkOrder::class)->handle($workOrder, 'diajukan', $workOrder->requester);
}

test('the urgent list shows up to five visible submitted mendesak work orders, oldest submission first', function () {
    $department = Department::factory()->create();
    $urgent = ['department_id' => $department->id, 'urgency' => WorkOrderUrgency::Mendesak];

    foreach (['2026-09-20 03:00' => 'Ketiga', '2026-09-20 01:00' => 'Pertama', '2026-09-22 00:00' => 'Kelima',
        '2026-09-20 02:00' => 'Kedua', '2026-09-23 00:00' => 'Keenam', '2026-09-21 00:00' => 'Keempat'] as $moment => $title) {
        workOrderSubmittedAt($moment, [...$urgent, 'title' => $title]);
    }
    workOrderSubmittedAt('2026-09-19 00:00', [...$urgent, 'title' => 'Tinggi', 'urgency' => WorkOrderUrgency::Tinggi]);
    workOrderSubmittedAt('2026-09-19 00:00', [...$urgent, 'title' => 'Terhapus'])->delete();
    workOrderSubmittedAt('2026-09-19 00:00', [...$urgent, 'title' => 'Departemen lain', 'department_id' => Department::factory()->create()->id]);
    $cancelled = workOrderSubmittedAt('2026-09-19 00:00', [...$urgent, 'title' => 'Dibatalkan']);
    app(TransitionWorkOrder::class)->handle($cancelled, 'dibatalkan', $cancelled->requester, 'Batal');
    WorkOrder::factory()->create([...$urgent, 'title' => 'Draft']);

    $first = WorkOrder::query()->where('title', 'Pertama')->with(['category', 'requester'])->sole();

    $this->actingAs(userInDepartment($department, Permission::WorkOrdersView))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->missing('urgentWorkOrders')
            ->loadDeferredProps(fn (Assert $reload): AssertableInertia => $reload
                ->where('urgentWorkOrders', fn (Collection $rows): bool => $rows->pluck('title')->all() === ['Pertama', 'Kedua', 'Ketiga', 'Keempat', 'Kelima'])
                ->where('urgentWorkOrders.0', [
                    'id' => $first->id,
                    'number' => $first->number,
                    'title' => 'Pertama',
                    'category' => $first->category->name,
                    'requester' => ['id' => $first->requester->id, 'name' => $first->requester->name],
                    'submitted_at' => '2026-09-20T01:00:00+00:00',
                ])));
});

test('the urgent list is empty when nothing mendesak is waiting', function () {
    $department = Department::factory()->create();
    WorkOrder::factory()->submitted()->create(['department_id' => $department->id, 'urgency' => WorkOrderUrgency::Tinggi]);

    $this->actingAs(userInDepartment($department, Permission::WorkOrdersView))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->loadDeferredProps(fn (Assert $reload): AssertableInertia => $reload
                ->where('urgentWorkOrders', [])));
});
