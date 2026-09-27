<?php

use App\Enums\Permission;
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
| FLOW.md §6: client company (IC) users never open the executor's internal
| pages and never receive its internal data. The route checks read the
| router, so a route added later is covered without editing this file.
*/

/**
 * Route name segments of the resources only the executor company manages.
 * A route whose name contains one must be internal, wherever it is defined.
 */
const INTERNAL_RESOURCES = ['users', 'roles', 'departments', 'work-order-categories', 'companies', 'activity-log', 'registrations'];

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
        'user' => User::factory()->create()->id,
        'role' => Role::findByName('viewer')->id,
        'subjectType' => 'company',
        'subjectId' => $company->id,
    ];
}

/**
 * The URL of the route with the given parameters.
 *
 * @param  array<string, int|string>  $parameters
 */
function adminRouteUrl(RoutingRoute $route, array $parameters): string
{
    return route((string) $route->getName(), array_intersect_key($parameters, array_flip($route->parameterNames())));
}

/**
 * Text that belongs to the executor company or to another IC department. It
 * must never reach an IC user's page props.
 *
 * @return list<string>
 */
function seedInternalSecrets(): array
{
    $unggul = Department::factory()->create(['code' => 'SECRET-UGL', 'name' => 'Rahasia Unggul Departemen']);
    User::factory()->for($unggul)->create(['name' => 'Rahasia Pelaksana', 'email' => 'rahasia.pelaksana@unggul.test']);

    $otherIc = Department::factory()->client()->create(['code' => 'SECRET-IC', 'name' => 'Rahasia IC Lain']);
    $otherRequester = User::factory()->for($otherIc)->create(['name' => 'Rahasia Pemohon Lain', 'email' => 'rahasia.pemohon@ic.test']);
    WorkOrder::factory()->by($otherRequester)->create(['title' => 'Rahasia WO IC Lain']);
    WorkOrder::factory()->by($otherRequester)->submitted()->create(['title' => 'Rahasia WO IC Lain Diajukan', 'urgency' => 'mendesak']);

    return [
        'SECRET-UGL', 'Rahasia Unggul Departemen', 'Rahasia Pelaksana', 'rahasia.pelaksana@unggul.test',
        'SECRET-IC', 'Rahasia IC Lain', 'Rahasia Pemohon Lain', 'rahasia.pemohon@ic.test', 'Rahasia WO IC Lain',
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

dataset('IC users', [
    'pemohon' => [fn (): User => icUser(Permission::WorkOrdersView, Permission::WorkOrdersCreate, Permission::WorkOrdersUpdate, Permission::WorkOrdersComment)],
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

    it('covers users, roles, departments, categories, companies, and the activity log', function () {
        expect(collect(adminRoutes())->pluck('name')->map(fn (string $name): string => explode('.', $name)[1])->unique()->sort()->values()->all())
            ->toBe(['activity-log', 'companies', 'departments', 'roles', 'users', 'work-order-categories']);
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
            ->and($user->checkPermissionTo(Permission::WorkOrdersViewAll->value))->toBeFalse()
            ->and($user->checkPermissionTo(Permission::WorkOrdersCreate->value))->toBeTrue();
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
        $own = WorkOrder::factory()->by($user)->create(['title' => 'Lampu gudang mati']);

        $pages = [
            'Daftar WO' => route('work-orders.index'),
            'Detail WO' => route('work-orders.show', $own),
            'Buat WO' => route('work-orders.create'),
        ];

        foreach ($pages as $name => $url) {
            $this->actingAs($user)->get($url)->assertOk()->assertInertia(function (Assert $page) use ($secrets, $name): Assert {
                assertNoInternalData($page->toArray()['props'], $secrets, $name);

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
