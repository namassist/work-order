<?php

use App\Enums\AccountStatus;
use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->ic = Company::factory()->client()->create(['name' => 'IC']);
    $this->icDepartment = Department::factory()->for($this->ic)->create(['code' => 'PRD']);
    $this->registration = User::factory()->for($this->icDepartment)->pending()->create(['name' => 'Rani']);
});

/**
 * Approve the test registration as the given user.
 *
 * @param  array<string, mixed>  $data
 */
function approveRegistration(User $reviewer, array $data = ['roles' => ['pemohon']], ?User $registration = null): TestResponse
{
    return test()->actingAs($reviewer)
        ->from(route('admin.registrations.index'))
        ->post(route('admin.registrations.approve', $registration ?? test()->registration), $data);
}

/**
 * Reject the test registration as the given user.
 */
function rejectRegistration(User $reviewer, mixed $reason = 'Bukan karyawan IC.', ?User $registration = null): TestResponse
{
    return test()->actingAs($reviewer)
        ->from(route('admin.registrations.index'))
        ->post(route('admin.registrations.reject', $registration ?? test()->registration), ['reason' => $reason]);
}

/**
 * The only entry logged on the registration since it was created (the
 * factory's "created" entry aside): no generic "updated" entry beside it.
 */
function reviewEntry(User $registration): Activity
{
    return Activity::whereMorphedTo('subject', $registration)->where('event', '!=', AuditEvent::Created->value)->sole();
}

describe('index', function () {
    it('lists pending registrations oldest first, never admin-created accounts', function () {
        User::factory()->pending()->create(['name' => 'Lebih Dulu', 'registered_at' => now()->subDay()]);
        User::factory()->rejected()->create(['name' => 'Ditolak']);
        User::factory()->create(['name' => 'Dibuat Admin']);

        $this->actingAs(userWithPermissions(Permission::RegistrationsView))
            ->get(route('admin.registrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->component('admin/registrations/Index')
                ->where('filters.status', 'pending')
                ->where('counts', ['pending' => 2, 'approved' => 0, 'rejected' => 1])
                ->has('registrations.data', 2)
                ->where('registrations.data.0.name', 'Lebih Dulu')
                ->where('registrations.data.1.name', 'Rani')
                ->where('registrations.data.1.company.name', 'IC')
                ->where('registrations.data.1.company.scope', 'client')
                ->where('registrations.data.1.department.code', 'PRD')
                ->where('can', ['approve' => false, 'reject' => false]));
    });

    it('lists rejected registrations with the reason and the reviewer, and searches them', function () {
        $reviewer = User::factory()->create(['name' => 'Admin Satu']);
        User::factory()->rejected('Email pribadi.')->create(['name' => 'Budi', 'reviewed_by' => $reviewer->id]);
        User::factory()->rejected()->create(['name' => 'Citra']);

        $this->actingAs(userWithPermissions(Permission::RegistrationsView))
            ->get(route('admin.registrations.index', ['status' => 'rejected', 'search' => 'bud']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->has('registrations.data', 1)
                ->where('registrations.data.0.rejection_reason', 'Email pribadi.')
                ->where('registrations.data.0.reviewer', 'Admin Satu'));
    });

    it('offers every role except those that manage roles', function () {
        $this->actingAs(adminUser())
            ->get(route('admin.registrations.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('roles', fn ($roles): bool => collect($roles)->pluck('name')->sort()->values()->all() === ['keuangan', 'koordinator', 'pelaksana', 'pemohon', 'viewer'])
                ->where('can', ['approve' => true, 'reject' => true]));
    });

    it('refuses an unknown status', function () {
        $this->actingAs(userWithPermissions(Permission::RegistrationsView))
            ->get(route('admin.registrations.index', ['status' => 'aktif']))
            ->assertSessionHasErrors('status');
    });
});

describe('approve', function () {
    it('grants the roles, records the reviewer, and lets the account in', function () {
        $admin = adminUser();

        approveRegistration($admin, ['roles' => ['pemohon', 'viewer']])
            ->assertRedirect(route('admin.registrations.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pendaftaran Rani disetujui.']);

        $user = $this->registration->refresh();
        expect($user->account_status)->toBe(AccountStatus::Approved)
            ->and($user->reviewed_by)->toBe($admin->id)
            ->and($user->reviewed_at)->not->toBeNull()
            ->and($user->department_id)->toBe($this->icDepartment->id)
            ->and($user->getRoleNames()->sort()->values()->all())->toBe(['pemohon', 'viewer']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    });

    it('logs one entry with the roles and the status', function () {
        $admin = adminUser();

        approveRegistration($admin, ['roles' => ['pemohon']]);

        $activity = reviewEntry($this->registration);
        expect($activity->event)->toBe(AuditEvent::RegistrationApproved->value)
            ->and($activity->causer_id)->toBe($admin->id)
            ->and($activity->attribute_changes->toArray())->toBe([
                'attributes' => ['account_status' => 'approved', 'roles' => ['pemohon']],
                'old' => ['account_status' => 'pending', 'roles' => []],
            ]);
    });

    it('corrects the department within the company and logs it', function () {
        $other = Department::factory()->for($this->ic)->create();

        approveRegistration(adminUser(), ['roles' => ['pemohon'], 'department_id' => $other->id])->assertSessionHasNoErrors();

        expect($this->registration->refresh()->department_id)->toBe($other->id)
            ->and(reviewEntry($this->registration)->attribute_changes['old']['department_id'])->toBe($this->icDepartment->id);
    });

    it('refuses a department of another company, or an inactive one', function (Closure $department) {
        approveRegistration(adminUser(), ['roles' => ['pemohon'], 'department_id' => $department()->id])
            ->assertSessionHasErrors(['department_id' => 'Pilih departemen aktif dari perusahaan IC.']);

        expect($this->registration->refresh()->account_status)->toBe(AccountStatus::Pending);
    })->with([
        'another client company' => fn () => Department::factory()->client()->create(),
        'the executor company' => fn () => Department::factory()->create(),
        'inactive' => fn () => Department::factory()->for(test()->ic)->inactive()->create(),
    ]);

    it('refuses roles that do not fit the company', function () {
        approveRegistration(adminUser(), ['roles' => ['pemohon', 'pelaksana']])
            ->assertSessionHasErrors(['roles' => 'Role pelaksana tidak dapat diberikan kepada pengguna IC (perusahaan klien).']);

        expect($this->registration->refresh()->roles)->toBeEmpty();
    });

    it('never grants role management, so never admin', function () {
        $unggulRegistration = User::factory()->for(Department::factory())->pending()->create();

        approveRegistration(adminUser(), ['roles' => ['admin']], $unggulRegistration)
            ->assertSessionHasErrors(['roles' => 'Role admin tidak dapat diberikan saat menyetujui pendaftaran; berikan lewat halaman Pengguna.']);

        expect($unggulRegistration->refresh()->account_status)->toBe(AccountStatus::Pending);
    });

    it('requires at least one existing role', function (array $data) {
        approveRegistration(adminUser(), $data)->assertSessionHasErrors('roles');
    })->with([
        'none' => [[]],
        'empty' => [['roles' => []]],
    ]);

    it('refuses an unknown role', function () {
        approveRegistration(adminUser(), ['roles' => ['direktur']])->assertSessionHasErrors('roles.0');
    });

    it('re-reviews a rejected registration and clears the reason', function () {
        $rejected = User::factory()->for($this->icDepartment)->rejected('Salah departemen.')->create();

        approveRegistration(adminUser(), ['roles' => ['pemohon']], $rejected)->assertSessionHasNoErrors();

        expect($rejected->refresh())
            ->account_status->toBe(AccountStatus::Approved)
            ->rejection_reason->toBeNull()
            ->and(reviewEntry($rejected)->attribute_changes['old'])
            ->toMatchArray(['account_status' => 'rejected', 'rejection_reason' => 'Salah departemen.']);
    });

    it('refuses a registration another admin approved meanwhile', function () {
        $this->registration->forceFill(['account_status' => AccountStatus::Approved])->save();

        approveRegistration(adminUser(), ['roles' => ['viewer']])
            ->assertRedirect(route('admin.registrations.index'))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Pendaftaran Rani sudah disetujui.']);

        expect($this->registration->refresh()->roles)->toBeEmpty();
    });

    it('answers 404 for an account that never registered itself', function () {
        approveRegistration(adminUser(), ['roles' => ['viewer']], User::factory()->for($this->icDepartment)->create())->assertNotFound();
    });
});

describe('reject', function () {
    it('rejects with the reason, which the account then sees', function () {
        $admin = adminUser();

        rejectRegistration($admin, '  Bukan karyawan IC.  ')
            ->assertRedirect(route('admin.registrations.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pendaftaran Rani ditolak.']);

        expect($this->registration->refresh())
            ->account_status->toBe(AccountStatus::Rejected)
            ->rejection_reason->toBe('Bukan karyawan IC.')
            ->reviewed_by->toBe($admin->id);

        $this->actingAs($this->registration)
            ->get(route('registration.status'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('registration.rejection_reason', 'Bukan karyawan IC.'));
    });

    it('logs one entry with the reason', function () {
        rejectRegistration(adminUser());

        $activity = reviewEntry($this->registration);
        expect($activity->event)->toBe(AuditEvent::RegistrationRejected->value)
            ->and($activity->attribute_changes->toArray())->toBe([
                'attributes' => ['account_status' => 'rejected', 'rejection_reason' => 'Bukan karyawan IC.'],
                'old' => ['account_status' => 'pending', 'rejection_reason' => null],
            ]);
    });

    it('requires a reason', function (mixed $reason) {
        rejectRegistration(adminUser(), $reason)->assertSessionHasErrors('reason');

        expect($this->registration->refresh()->account_status)->toBe(AccountStatus::Pending);
    })->with(['missing' => null, 'blank' => '   ', 'too long' => str_repeat('a', 501)]);

    it('refuses a registration that is no longer pending', function (string $state, string $message) {
        $user = User::factory()->for($this->icDepartment)->{$state}()->create(['name' => 'Budi']);

        rejectRegistration(adminUser(), 'Alasan.', $user)
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => $message]);
    })->with([
        ['rejected', 'Pendaftaran Budi sudah ditolak; setujui jika ingin meninjau ulang.'],
    ]);
});

it('forbids each endpoint without its permission', function (string $method, Closure $url, Permission $granted) {
    $this->actingAs(userWithPermissions($granted))
        ->call($method, $url(), ['roles' => ['pemohon'], 'reason' => 'Alasan.'])
        ->assertForbidden();
})->with([
    'index' => ['GET', fn (): string => route('admin.registrations.index'), Permission::UsersView],
    'approve' => ['POST', fn (): string => route('admin.registrations.approve', test()->registration), Permission::RegistrationsReject],
    'reject' => ['POST', fn (): string => route('admin.registrations.reject', test()->registration), Permission::RegistrationsApprove],
]);

it('lets approvers and rejecters act with only their own permission', function () {
    approveRegistration(userWithPermissions(Permission::RegistrationsApprove))->assertSessionHasNoErrors();

    $other = User::factory()->for($this->icDepartment)->pending()->create();
    rejectRegistration(userWithPermissions(Permission::RegistrationsReject), 'Alasan.', $other)->assertSessionHasNoErrors();

    expect($this->registration->refresh()->account_status)->toBe(AccountStatus::Approved)
        ->and($other->refresh()->account_status)->toBe(AccountStatus::Rejected);
});

it('shares the pending count for the sidebar badge only with reviewers', function () {
    User::factory()->pending()->create();
    User::factory()->rejected()->create();

    $this->actingAs(userWithPermissions(Permission::RegistrationsView))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('pendingRegistrations', 2));

    $this->actingAs(userWithPermissions(Permission::UsersView))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('pendingRegistrations', null));

    $this->actingAs(icUser(...Permission::cases()))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('pendingRegistrations', null));
});
