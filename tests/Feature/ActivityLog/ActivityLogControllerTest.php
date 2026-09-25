<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrderCategory;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * A user who may read the activity log.
 */
function auditor(): User
{
    return userWithPermissions(Permission::ActivityLogView);
}

describe('index', function () {
    it('lists activity newest first with readable changes', function () {
        $auditor = auditor();
        $finance = Department::factory()->create(['code' => 'FIN']);
        $operations = Department::factory()->create(['code' => 'OPS']);
        $user = User::factory()->for($finance)->create(['name' => 'Budi']);
        $this->actingAs($auditor);
        $user->update(['department_id' => $operations->id, 'is_active' => false]);

        $this->get(route('admin.activity-log.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->component('admin/activity-log/Index')
                ->where('activities.total', 5)
                ->where('activities.data.0.event', 'deactivated')
                ->where('activities.data.0.event_label', 'Dinonaktifkan')
                ->where('activities.data.0.subject', ['type' => 'user', 'type_label' => 'Pengguna', 'id' => $user->id, 'label' => 'Budi'])
                ->where('activities.data.0.causer', ['id' => $auditor->id, 'name' => $auditor->name])
                ->where('activities.data.0.changes', [
                    ['field' => 'department_id', 'label' => 'Departemen', 'old' => 'FIN', 'new' => 'OPS'],
                    ['field' => 'is_active', 'label' => 'Status', 'old' => 'Aktif', 'new' => 'Nonaktif'],
                ]));
    });

    it('shows list changes and auth details as they were logged', function () {
        $user = User::factory()->create();
        activity('auth')->performedOn($user)->event('login_failed')
            ->withProperties(['email' => $user->email, 'ip' => '10.0.0.8'])->log('login_failed');
        activity()->performedOn($user)->event('roles_updated')
            ->withChanges(['attributes' => ['roles' => ['keuangan']], 'old' => ['roles' => ['viewer']]])->log('roles_updated');

        $this->actingAs(auditor())
            ->get(route('admin.activity-log.index', ['subject_type' => 'user', 'subject_id' => $user->id]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('activities.data.0.changes', [['field' => 'roles', 'label' => 'Role', 'old' => ['viewer'], 'new' => ['keuangan']]])
                ->where('activities.data.1.log_name', 'auth')
                ->where('activities.data.1.properties', ['email' => $user->email, 'ip' => '10.0.0.8']));
    });

    it('keeps the label of a subject that no longer exists', function () {
        $role = Role::create(['name' => 'teknisi', 'guard_name' => 'web']);
        activity()->performedOn($role)->event('deleted')
            ->withChanges(['old' => ['name' => 'teknisi', 'permissions' => []]])->log('deleted');
        $role->delete();

        $this->actingAs(auditor())
            ->get(route('admin.activity-log.index', ['subject_type' => 'role']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('activities.data.0.subject.label', 'teknisi'));
    });

    it('filters by subject type, causer, and event', function () {
        $auditor = auditor();
        $other = User::factory()->create();
        $department = Department::factory()->create();
        WorkOrderCategory::factory()->create();
        $this->actingAs($other);
        $department->update(['name' => 'Baru']);

        $this->actingAs($auditor)
            ->get(route('admin.activity-log.index', ['subject_type' => 'department', 'causer' => $other->id, 'event' => 'updated']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('activities.total', 1)
                ->where('activities.data.0.subject.id', $department->id)
                ->where('filters', ['subject_type' => 'department', 'subject_id' => '', 'causer' => (string) $other->id, 'event' => 'updated', 'from' => '', 'to' => '']));
    });

    it('filters by an inclusive date range', function () {
        $auditor = auditor();
        foreach (['2026-09-01 08:00', '2026-09-10 23:59', '2026-09-11 00:00'] as $moment) {
            activity()->createdAt(Carbon::parse($moment))->event('updated')->log('updated');
        }

        $this->actingAs($auditor)
            ->get(route('admin.activity-log.index', ['from' => '2026-09-01', 'to' => '2026-09-10', 'event' => 'updated']))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('activities.total', 2));
    });

    it('offers only users who caused activity as causer options', function () {
        $auditor = auditor();
        User::factory()->create(['name' => 'Tidak Pernah']);
        $this->actingAs($auditor);
        Department::factory()->create();

        $this->get(route('admin.activity-log.index'))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('causers', [['id' => $auditor->id, 'name' => $auditor->name]]));
    });

    it('rejects unknown filter values', function () {
        $this->actingAs(auditor())
            ->get(route('admin.activity-log.index', ['subject_type' => User::class, 'event' => 'dropped', 'from' => '2026-09-10', 'to' => '2026-09-01']))
            ->assertSessionHasErrors(['subject_type', 'event', 'to']);
    });

    it('paginates 15 entries per page', function () {
        $auditor = auditor();
        Department::factory()->count(15)->create();

        $this->actingAs($auditor)
            ->get(route('admin.activity-log.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page->has('activities.data', 1));
    });
});

describe('history', function () {
    it('returns the activity of one record, newest first', function () {
        $auditor = auditor();
        $department = Department::factory()->create(['name' => 'Lama']);
        Department::factory()->create();
        $department->update(['name' => 'Baru']);

        $this->actingAs($auditor)
            ->getJson(route('admin.activity-log.history', ['subjectType' => 'department', 'subjectId' => $department->id]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'updated')
            ->assertJsonPath('data.0.changes.0', ['field' => 'name', 'label' => 'Nama', 'old' => 'Lama', 'new' => 'Baru'])
            ->assertJsonPath('data.1.event', 'created');
    });

    it('includes auth events in a user history', function () {
        $user = User::factory()->create();
        activity('auth')->performedOn($user)->event('login')->log('login');

        $this->actingAs(auditor())
            ->getJson(route('admin.activity-log.history', ['subjectType' => 'user', 'subjectId' => $user->id]))
            ->assertJsonPath('data.0.event', 'login');
    });

    it('only serves the modules that have a history panel', function () {
        $this->actingAs(auditor())
            ->getJson('/admin/activity-log/role/1')
            ->assertNotFound();
    });
});

describe('authorization', function () {
    it('redirects guests to login', function () {
        $this->get(route('admin.activity-log.index'))->assertRedirect(route('login'));
    });

    it('forbids users without the activity log permission', function (Closure $url) {
        $this->actingAs(userWithPermissions(Permission::UsersView, Permission::DepartmentsUpdate))
            ->get($url())
            ->assertForbidden();
    })->with([
        'index' => [fn (): string => route('admin.activity-log.index')],
        'history' => [fn (): string => route('admin.activity-log.history', ['subjectType' => 'department', 'subjectId' => 1])],
    ]);
});
