<?php

use App\Enums\Permission;
use App\Models\BastTemplateVersion;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/*
| FLOW.md §2, §6: client company (IC) users never open the executor's
| internal pages and never receive its internal data. IC never logs in (v2),
| so no IC account is issued; this stays as a safeguard. The route checks
| read the router, so a route added later is covered without editing this
| file.
*/

/**
 * Route name segments of the resources only the executor company manages.
 * A route whose name contains one must be internal, wherever it is defined.
 */
const INTERNAL_RESOURCES = ['users', 'roles', 'departments', 'work-order-categories', 'companies', 'activity-log', 'registrations', 'bast-template'];

/**
 * Query parameters a route needs besides its route parameters, by route name.
 */
const ADMIN_ROUTE_QUERIES = ['admin.bast-template.preview' => ['work_order']];

/**
 * Every admin.* route with the HTTP method to call it with.
 *
 * @return list<array{name: string, method: string, route: RoutingRoute}>
 */
function adminRoutes(): array
{
    return array_values(collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => Str::startsWith((string) $route->getName(), 'admin.'))
        ->map(fn (RoutingRoute $route): array => [
            'name' => (string) $route->getName(),
            'method' => collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')->first(),
            'route' => $route,
        ])
        ->all());
}

/**
 * An existing record for every parameter an admin route takes, so a 404
 * comes from the isolation and not from a missing record.
 *
 * @return array<string, int|string>
 */
function adminRouteParameters(): array
{
    $company = Company::factory()->create();

    return [
        'company' => $company->id,
        'department' => Department::factory()->for($company)->create()->id,
        'category' => WorkOrderCategory::factory()->create()->id,
        // A registration under review, so the Pendaftaran actions would reach it too.
        'user' => User::factory()->pending()->create()->id,
        'role' => Role::findByName('viewer')->id,
        'subjectType' => 'company',
        'subjectId' => $company->id,
        'version' => BastTemplateVersion::factory()->create()->id,
        // A submitted work order, which the BAST template preview is filled from.
        'work_order' => WorkOrder::factory()->submitted()->create()->id,
    ];
}

/**
 * The URL of the route with the given parameters.
 *
 * @param  array<string, int|string>  $parameters
 */
function adminRouteUrl(RoutingRoute $route, array $parameters): string
{
    $names = [...$route->parameterNames(), ...(ADMIN_ROUTE_QUERIES[$route->getName()] ?? [])];

    return route((string) $route->getName(), array_intersect_key($parameters, array_flip($names)));
}

/**
 * Text that belongs to the executor company or to another IC department. It
 * must never reach an IC user's page props. Active Unggul departments are
 * the one exception: the list's target filter names them (id, code, name),
 * see assertTargetDepartmentsOnly(); the secret ones here are inactive or
 * deleted, so no list offers them.
 *
 * @return list<string>
 */
function seedInternalSecrets(): array
{
    $unggul = Department::factory()->inactive()->create(['code' => 'SECRET-UGL', 'name' => 'Rahasia Unggul Departemen']);
    User::factory()->for($unggul)->create(['name' => 'Rahasia Pelaksana', 'email' => 'rahasia.pelaksana@unggul.test']);
    Department::factory()->create(['code' => 'SECRET-DEL', 'name' => 'Rahasia Unggul Terhapus'])->delete();

    $otherIc = Department::factory()->client()->create(['code' => 'SECRET-IC', 'name' => 'Rahasia IC Lain']);
    User::factory()->for($otherIc)->create(['name' => 'Rahasia Akun IC Lain', 'email' => 'rahasia.pemohon@ic.test']);
    WorkOrder::factory()->requestedBy($otherIc)->create(['title' => 'Rahasia WO IC Lain', 'requester_name' => 'Rahasia Pemohon Lain']);
    WorkOrder::factory()->requestedBy($otherIc)->submitted()->create(['title' => 'Rahasia WO IC Lain Diajukan', 'urgency' => 'mendesak', 'requester_name' => 'Rahasia Pemohon Lain']);

    return [
        'SECRET-UGL', 'Rahasia Unggul Departemen', 'Rahasia Pelaksana', 'rahasia.pelaksana@unggul.test',
        'SECRET-DEL', 'Rahasia Unggul Terhapus',
        'SECRET-IC', 'Rahasia IC Lain', 'Rahasia Akun IC Lain', 'Rahasia Pemohon Lain', 'rahasia.pemohon@ic.test', 'Rahasia WO IC Lain',
    ];
}

/**
 * Fails when any of the secrets appears in the page props, or when the props
 * hold a list of users.
 *
 * @param  array<string, mixed>  $props
 * @param  list<string>  $secrets
 */
function assertNoInternalData(array $props, array $secrets, string $page): void
{
    $json = (string) json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    foreach ($secrets as $secret) {
        expect(str_contains($json, $secret))->toBeFalse("{$page} leaks \"{$secret}\" to an IC user.");
    }

    expect(array_key_exists('users', $props))->toBeFalse("{$page} gives an IC user a user list.");
}

/**
 * The work order list's target filter for an IC user: exactly the active
 * executor departments, and nothing about them beyond id, code, and name.
 *
 * @param  array<string, mixed>  $props
 */
function assertTargetDepartmentsOnly(array $props): void
{
    $targets = collect($props['targetDepartments'] ?? []);

    expect($targets->every(fn (array $department): bool => array_keys($department) === ['id', 'code', 'name']))->toBeTrue()
        ->and($targets->pluck('id')->sort()->values()->all())->toBe(Department::query()
        ->where('is_active', true)
        ->whereRelation('company', 'is_client', false)
        ->orderBy('id')
        ->pluck('id')
        ->all());
}

dataset('IC users', [
    'holding the v1 pemohon permissions' => [fn (): User => icUser(Permission::WorkOrdersView, Permission::WorkOrdersCreate, Permission::WorkOrdersUpdate, Permission::WorkOrdersComment)],
    'holding every permission directly' => [fn (): User => icUser(...Permission::cases())],
    'with the admin role' => [fn (): User => icUser()->assignRole('admin')],
]);

describe('internal routes', function () {
    it('keeps every route of an internal resource in the internal group', function () {
        $pattern = '/(^|\.)('.implode('|', array_map(preg_quote(...), INTERNAL_RESOURCES)).')(\.|$)/';

        $unguarded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => preg_match($pattern, (string) $route->getName()) === 1)
            ->reject(fn (RoutingRoute $route): bool => in_array('internal', $route->gatherMiddleware(), true))
            ->map(fn (RoutingRoute $route): string => (string) $route->getName())
            ->values()
            ->all();

        expect($unguarded)->toBe([]);
    });

    it('covers users, roles, registrations, departments, categories, companies, the activity log, and the BAST template', function () {
        expect(collect(adminRoutes())->pluck('name')->map(fn (string $name): string => explode('.', $name)[1])->unique()->sort()->values()->all())
            ->toBe(['activity-log', 'bast-template', 'companies', 'departments', 'registrations', 'roles', 'users', 'work-order-categories']);
    });

    it('answers 404 to an IC user on every admin route, whatever they hold', function (Closure $icUser) {
        $user = $icUser();
        $parameters = adminRouteParameters();

        $reached = collect(adminRoutes())
            ->map(fn (array $admin): array => [
                'route' => "{$admin['method']} {$admin['name']}",
                'status' => $this->actingAs($user)->call($admin['method'], adminRouteUrl($admin['route'], $parameters))->getStatusCode(),
            ])
            ->reject(fn (array $result): bool => $result['status'] === 404)
            ->values()
            ->all();

        expect($reached)->toBe([]);
    })->with('IC users');

    it('lets an Unggul admin open every admin page with the same parameters', function () {
        $admin = adminUser();
        $parameters = adminRouteParameters();

        $failed = collect(adminRoutes())
            ->filter(fn (array $route): bool => $route['method'] === 'GET')
            ->map(fn (array $route): array => [
                'route' => $route['name'],
                'status' => $this->actingAs($admin)->get(adminRouteUrl($route['route'], $parameters))->getStatusCode(),
            ])
            ->reject(fn (array $result): bool => $result['status'] === 200)
            ->values()
            ->all();

        expect($failed)->toBe([]);
    });
});

describe('permissions', function () {
    it('ignores internal-only permissions of an IC user in checks and in the shared props', function () {
        $user = icUser(...Permission::cases());

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('auth.isClient', true)
                ->where('auth.permissions', fn ($permissions): bool => collect($permissions)->sort()->values()->all()
                    === collect(Permission::values())->diff(Permission::internalOnlyValues())->sort()->values()->all()));

        foreach (Permission::cases() as $permission) {
            expect($user->checkPermissionTo($permission->value))->toBe(! $permission->isInternalOnly(), $permission->value);
        }
    });

    it('ignores internal-only permissions an IC user gets through a role', function () {
        $user = icUser()->assignRole('admin');

        expect($user->checkPermissionTo(Permission::UsersView->value))->toBeFalse()
            ->and($user->checkPermissionTo(Permission::WorkOrdersCreate->value))->toBeFalse()
            ->and($user->checkPermissionTo(Permission::WorkOrdersExport->value))->toBeFalse()
            ->and($user->checkPermissionTo(Permission::WorkOrdersView->value))->toBeTrue();
    });

    it('keeps every permission for an Unggul user', function () {
        $user = unggulUser(...Permission::cases());

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('auth.isClient', false)
                ->where('auth.permissions', fn ($permissions): bool => count($permissions) === count(Permission::cases())));

        expect(collect(Permission::cases())->every(fn (Permission $permission): bool => $user->checkPermissionTo($permission->value)))->toBeTrue();
    });
});

describe('page props', function () {
    it('keeps internal data out of the pages an IC user opens', function (Closure $icUser) {
        $user = $icUser();
        $secrets = seedInternalSecrets();
        Department::factory()->create(['code' => 'TUJUAN']);
        // Entered for the IC user's department by an Unggul Admin WO: IC
        // users see the Admin WO's name, and nothing else about them.
        $adminWo = User::factory()->create(['name' => 'Admin WO Unggul', 'email' => 'rahasia.adminwo@unggul.test']);
        $own = WorkOrder::factory()->by($adminWo)->requestedBy($user->department)->create(['title' => 'Lampu gudang mati', 'requester_name' => 'Pak Andi']);
        $secrets[] = 'rahasia.adminwo@unggul.test';
        // Invoiced and paid by Unggul users: IC users see the invoice (they
        // pay it) and those users' names, nothing else about them.
        $pelaksana = User::factory()->create(['name' => 'Pelaksana Unggul', 'email' => 'penagih.rahasia@unggul.test']);
        $keuangan = User::factory()->create(['name' => 'Keuangan Unggul', 'email' => 'rahasia.keuangan@unggul.test']);
        $paid = WorkOrder::factory()->requestedBy($user->department)->paid(['issued_by' => $pelaksana->id, 'paid_by' => $keuangan->id])->create();
        $secrets[] = 'penagih.rahasia@unggul.test';
        $secrets[] = 'rahasia.keuangan@unggul.test';

        $pages = [
            'Daftar WO' => route('work-orders.index'),
            'Detail WO' => route('work-orders.show', $own),
            'Detail WO lunas' => route('work-orders.show', $paid),
            'Buat WO' => route('work-orders.create'),
            'Ubah WO' => route('work-orders.edit', $own),
        ];

        foreach ($pages as $name => $url) {
            $response = $this->actingAs($user)->get($url);

            // IC users never create or edit work orders (FLOW.md v2 §4).
            if (in_array($name, ['Buat WO', 'Ubah WO'], true)) {
                $response->assertForbidden();

                continue;
            }

            $response->assertOk()->assertInertia(function (Assert $page) use ($secrets, $name): Assert {
                $props = $page->toArray()['props'];
                assertNoInternalData($props, $secrets, $name);

                if ($name === 'Daftar WO') {
                    assertTargetDepartmentsOnly($props);
                }

                if ($name === 'Detail WO lunas') {
                    expect($props['invoice']['issued_by'])->toBe(['name' => 'Pelaksana Unggul'])
                        ->and($props['invoice']['paid_by'])->toBe(['name' => 'Keuangan Unggul']);
                }

                if ($name === 'Detail WO') {
                    expect($props['workOrder']['entered_by'])->toBe(['name' => 'Admin WO Unggul']);
                }

                return $page;
            });
        }

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(function (Assert $page) use ($secrets): Assert {
            assertNoInternalData($page->toArray()['props'], $secrets, 'Dashboard');

            return $page
                ->where('recentActivities', null)
                ->loadDeferredProps(function (Assert $reload) use ($secrets): Assert {
                    assertNoInternalData($reload->toArray()['props'], $secrets, 'Dashboard (deferred)');

                    return $reload->has('workOrderCounts')->has('recentWorkOrders');
                });
        });
    })->with('IC users');

    it('offers an IC user no department filter on the work order list', function (Closure $icUser) {
        $this->actingAs($icUser())
            ->get(route('work-orders.index'))
            ->assertInertia(fn (Assert $page): Assert => $page->where('departments', null));
    })->with('IC users');
});
