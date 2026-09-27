<?php

use App\Enums\AccountStatus;
use App\Enums\AuditEvent;
use App\Http\Middleware\EnsureRegistrationIsEnabled;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->ic = Company::factory()->client()->create(['name' => 'IC', 'email_domains' => ['ic.test']]);
    $this->unggul = Company::factory()->create(['name' => 'Unggul', 'email_domains' => ['unggul.test', 'unggul.co.id']]);
    $this->icDepartment = Department::factory()->for($this->ic)->create(['code' => 'PRD']);
    $this->unggulDepartment = Department::factory()->for($this->unggul)->create(['code' => 'ENG']);
});

/**
 * A valid registration for the IC production department, with overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registration(array $overrides = []): array
{
    return [
        'name' => 'Rani Wijaya',
        'email' => 'rani@ic.test',
        'password' => 'Rahasia-Kuat-2026',
        'password_confirmation' => 'Rahasia-Kuat-2026',
        'company_id' => test()->ic->id,
        'department_id' => test()->icDepartment->id,
        ...$overrides,
    ];
}

describe('page', function () {
    it('offers only active companies with a domain and their active departments, with exactly these keys', function () {
        Company::factory()->inactive()->create(['email_domains' => ['old.test']]);
        Company::factory()->create(['email_domains' => []]);
        Company::factory()->create(['email_domains' => ['gone.test']])->delete();
        Department::factory()->for($this->ic)->inactive()->create();
        Department::factory()->for($this->ic)->create()->delete();
        Department::factory()->for(Company::factory()->inactive())->create();
        User::factory()->for($this->icDepartment)->create();

        $this->get(route('register'))->assertOk()->assertInertia(function (Assert $page) {
            $page->component('auth/Register')
                ->has('companies', 2)
                ->has('companies.0', fn (Assert $company): AssertableInertia => $company
                    ->where('id', $this->ic->id)
                    ->where('name', 'IC')
                    ->where('email_domains', ['ic.test']))
                ->has('companies.1', fn (Assert $company): AssertableInertia => $company
                    ->where('id', $this->unggul->id)
                    ->where('name', 'Unggul')
                    ->where('email_domains', ['unggul.test', 'unggul.co.id']))
                ->has('departments', 2)
                ->has('departments.0', fn (Assert $department): AssertableInertia => $department
                    ->where('id', $this->unggulDepartment->id)
                    ->where('code', 'ENG')
                    ->where('name', $this->unggulDepartment->name)
                    ->where('company_id', $this->unggul->id))
                ->has('departments.1', fn (Assert $department): AssertableInertia => $department
                    ->where('id', $this->icDepartment->id)
                    ->etc());

            $pageProps = array_diff(array_keys($page->toArray()['props']), ['name', 'auth', 'pendingRegistrations', 'displayTimezone', 'sidebarOpen', 'errors']);
            expect(array_values($pageProps))->toEqualCanonicalizing(['companies', 'departments', 'passwordRules']);
        });
    });

    it('links to registration from the login page only while it is enabled', function (bool $enabled) {
        config(['registration.enabled' => $enabled]);

        $this->get(route('login'))->assertInertia(fn (Assert $page): AssertableInertia => $page->where('canRegister', $enabled));
    })->with([true, false]);
});

describe('registering', function () {
    it('creates a pending account without roles or permissions and signs it in', function () {
        $this->post(route('register.store'), registration())->assertRedirect();

        $user = User::where('email', 'rani@ic.test')->sole();

        $this->assertAuthenticatedAs($user);
        expect($user->account_status)->toBe(AccountStatus::Pending)
            ->and($user->registered_at)->not->toBeNull()
            ->and($user->is_active)->toBeTrue()
            ->and($user->must_change_password)->toBeFalse()
            ->and($user->department_id)->toBe($this->icDepartment->id)
            ->and($user->roles)->toBeEmpty()
            ->and($user->getAllPermissions())->toBeEmpty();
    });

    it('matches the email domain case-insensitively and stores the email in lowercase', function () {
        $this->post(route('register.store'), registration(['email' => 'Rani.Wijaya@IC.TEST']))->assertSessionHasNoErrors();

        expect(User::sole()->email)->toBe('rani.wijaya@ic.test');
    });

    it('accepts any of the company domains', function () {
        $this->post(route('register.store'), registration([
            'email' => 'budi@unggul.co.id',
            'company_id' => $this->unggul->id,
            'department_id' => $this->unggulDepartment->id,
        ]))->assertSessionHasNoErrors();

        expect(User::sole()->department_id)->toBe($this->unggulDepartment->id);
    });

    it('refuses an email whose domain is not the chosen company\'s', function (string $email) {
        $this->post(route('register.store'), registration(['email' => $email]))
            ->assertSessionHasErrors(['email' => 'Email harus memakai domain IC (ic.test).']);

        $this->assertGuest();
        expect(User::count())->toBe(0);
    })->with([
        'another company' => 'rani@unggul.test',
        'a subdomain' => 'rani@mail.ic.test',
        'a lookalike' => 'rani@ic.test.evil.test',
        'an unknown domain' => 'rani@gmail.com',
    ]);

    it('refuses a department of another company, or an inactive or deleted one', function (Closure $department) {
        $this->post(route('register.store'), registration(['department_id' => $department()->id]))
            ->assertSessionHasErrors(['department_id' => 'Pilih departemen aktif dari perusahaan yang dipilih.']);

        expect(User::count())->toBe(0);
    })->with([
        'another company' => fn () => test()->unggulDepartment,
        'inactive' => fn () => Department::factory()->for(test()->ic)->inactive()->create(),
        'deleted' => fn () => tap(Department::factory()->for(test()->ic)->create())->delete(),
    ]);

    it('refuses an inactive or deleted company', function (Closure $change) {
        $change($this->ic);

        $this->post(route('register.store'), registration())->assertSessionHasErrors('company_id');

        expect(User::count())->toBe(0);
    })->with([
        'inactive' => fn (Company $company) => $company->update(['is_active' => false]),
        'deleted' => fn (Company $company) => $company->delete(),
    ]);

    it('requires a confirmed password that meets the defaults, and every field', function () {
        $this->post(route('register.store'), registration(['password_confirmation' => 'lain']))->assertSessionHasErrors('password');
        $this->post(route('register.store'), [])->assertSessionHasErrors(['name', 'email', 'password', 'company_id', 'department_id']);
    });

    it('refuses an email that is already registered, even by a deleted account', function () {
        User::factory()->create(['email' => 'rani@ic.test'])->delete();

        $this->post(route('register.store'), registration())->assertSessionHasErrors('email');
    });

    it('ignores status, roles, permissions, and flags sent with the request', function () {
        $this->seed(RolePermissionSeeder::class);

        $this->post(route('register.store'), registration([
            'account_status' => 'approved',
            'is_active' => false,
            'must_change_password' => true,
            'roles' => ['admin', 'pemohon'],
            'permissions' => ['users.view'],
            'registered_at' => null,
            'reviewed_by' => 1,
        ]))->assertSessionHasNoErrors();

        $user = User::sole();

        expect($user->account_status)->toBe(AccountStatus::Pending)
            ->and($user->is_active)->toBeTrue()
            ->and($user->must_change_password)->toBeFalse()
            ->and($user->registered_at)->not->toBeNull()
            ->and($user->reviewed_by)->toBeNull()
            ->and($user->roles)->toBeEmpty()
            ->and($user->getAllPermissions())->toBeEmpty();
    });

    it('logs one "registered" entry, caused by the new account, with the IP', function () {
        $this->post(route('register.store'), registration());

        $user = User::sole();
        $activity = Activity::where('log_name', 'audit')->whereMorphedTo('subject', $user)->sole();

        expect($activity->event)->toBe(AuditEvent::Registered->value)
            ->and($activity->subject_id)->toBe($user->id)
            ->and($activity->causer_id)->toBe($user->id)
            ->and($activity->properties['ip'])->toBe('127.0.0.1')
            ->and($activity->attribute_changes['attributes'])->toBe([
                'name' => 'Rani Wijaya',
                'email' => 'rani@ic.test',
                'department_id' => $this->icDepartment->id,
                'account_status' => 'pending',
            ]);
    });
});

describe('kill switch', function () {
    it('answers 404 on the page and the form when registration is disabled', function () {
        config(['registration.enabled' => false]);

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), registration())->assertNotFound();

        $this->assertGuest();
        expect(User::count())->toBe(0);
    });

    it('guards both registration routes', function () {
        expect(Route::getRoutes()->getByName('register')->gatherMiddleware())->toContain(EnsureRegistrationIsEnabled::class)
            ->and(Route::getRoutes()->getByName('register.store')->gatherMiddleware())->toContain(EnsureRegistrationIsEnabled::class);
    });
});

describe('rate limit', function () {
    it('uses the named registration limiter on the form', function () {
        expect(Route::getRoutes()->getByName('register.store')->gatherMiddleware())->toContain('throttle:registration');
    });

    it('allows five registration attempts per minute from one IP', function () {
        foreach (range(1, 5) as $attempt) {
            $this->post(route('register.store'), registration(['email' => "nope{$attempt}@gmail.com"]))->assertSessionHasErrors('email');
        }

        $this->post(route('register.store'), registration())->assertTooManyRequests();
        expect(User::count())->toBe(0);
    });

    it('allows twenty registration attempts per hour from one IP, counted apart from the minute', function () {
        foreach (range(1, 20) as $attempt) {
            if ($attempt > 1 && $attempt % 5 === 1) {
                $this->travel(61)->seconds();
            }

            $this->post(route('register.store'), registration(['email' => "nope{$attempt}@gmail.com"]))->assertSessionHasErrors('email');
        }

        $this->travel(61)->seconds();
        $this->post(route('register.store'), registration())->assertTooManyRequests();

        $this->travel(1)->hours();
        $this->post(route('register.store'), registration())->assertSessionHasNoErrors();
    });
});
