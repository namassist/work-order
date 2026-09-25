<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * The activities with the given event and subject type, oldest first.
 *
 * @return list<Activity>
 */
function activitiesWithEvent(string $event, string $subjectType): array
{
    return Activity::query()->forEvent($event)->where('subject_type', $subjectType)->orderBy('id')->get()->all();
}

describe('user roles', function () {
    it('logs the roles before and after an admin changes them', function () {
        $admin = adminUser();
        $user = User::factory()->for(Department::factory())->create()->assignRole('viewer', 'pemohon');

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'department_id' => $user->department_id,
            'is_active' => true,
            'roles' => ['keuangan', 'pemohon'],
        ])->assertSessionHasNoErrors();

        [$activity] = activitiesWithEvent('roles_updated', 'user');
        expect($activity)
            ->subject_type->toBe('user')
            ->subject_id->toBe($user->id)
            ->causer_id->toBe($admin->id)
            ->and($activity->attribute_changes->all())->toBe([
                'attributes' => ['roles' => ['keuangan', 'pemohon']],
                'old' => ['roles' => ['pemohon', 'viewer']],
            ]);
    });

    it('logs nothing when the roles are unchanged', function () {
        $admin = adminUser();
        $user = User::factory()->for(Department::factory())->create()->assignRole('viewer');

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'department_id' => $user->department_id,
            'is_active' => true,
            'roles' => ['viewer'],
        ])->assertSessionHasNoErrors();

        expect(activitiesWithEvent('roles_updated', 'user'))->toBeEmpty();
    });

    it('logs the roles given to a new user', function () {
        config(['auth.default_user_password' => 'password-awal']);

        $this->actingAs(adminUser())->post(route('admin.users.store'), [
            'name' => 'Budi',
            'email' => 'budi@example.com',
            'department_id' => Department::factory()->create()->id,
            'roles' => ['viewer'],
        ])->assertSessionHasNoErrors();

        [$activity] = activitiesWithEvent('roles_updated', 'user');
        expect($activity->subject_id)->toBe(User::where('email', 'budi@example.com')->value('id'))
            ->and($activity->attribute_changes->all())->toBe([
                'attributes' => ['roles' => ['viewer']],
                'old' => ['roles' => []],
            ]);
    });
});

describe('roles', function () {
    it('logs a new role with its permissions', function () {
        $this->actingAs(userWithPermissions(Permission::RolesManage))->post(route('admin.roles.store'), [
            'name' => 'teknisi',
            'permissions' => [Permission::WorkOrdersView->value, Permission::WorkOrdersUpdate->value],
        ])->assertSessionHasNoErrors();

        [$activity] = activitiesWithEvent('created', 'role');
        expect($activity)
            ->log_name->toBe('audit')
            ->subject_type->toBe('role')
            ->subject_id->toBe(Role::findByName('teknisi')->id)
            ->and($activity->attribute_changes->get('attributes'))->toBe([
                'name' => 'teknisi',
                'permissions' => [Permission::WorkOrdersUpdate->value, Permission::WorkOrdersView->value],
            ]);
    });

    it('logs a rename and permission change with before and after values', function () {
        $manager = userWithPermissions(Permission::RolesManage);
        $role = Role::findByName('viewer')->syncPermissions([Permission::DepartmentsView->value, Permission::WorkOrdersView->value]);

        $this->actingAs($manager)->put(route('admin.roles.update', $role), [
            'name' => 'pengamat',
            'permissions' => [Permission::WorkOrdersView->value, Permission::UsersView->value],
        ])->assertSessionHasNoErrors();

        [$activity] = activitiesWithEvent('updated', 'role');
        expect($activity->subject_id)->toBe($role->id)
            ->and($activity->attribute_changes->all())->toBe([
                'attributes' => ['name' => 'pengamat', 'permissions' => [Permission::UsersView->value, Permission::WorkOrdersView->value]],
                'old' => ['name' => 'viewer', 'permissions' => [Permission::DepartmentsView->value, Permission::WorkOrdersView->value]],
            ]);
    });

    it('logs only the permissions when the name is unchanged', function () {
        $manager = userWithPermissions(Permission::RolesManage);
        $role = Role::findByName('viewer')->syncPermissions([Permission::DepartmentsView->value]);

        $this->actingAs($manager)->put(route('admin.roles.update', $role), [
            'name' => 'viewer',
            'permissions' => [Permission::DepartmentsView->value, Permission::UsersView->value],
        ])->assertSessionHasNoErrors();

        [$activity] = activitiesWithEvent('updated', 'role');
        expect($activity->attribute_changes->all())->toBe([
            'attributes' => ['permissions' => [Permission::DepartmentsView->value, Permission::UsersView->value]],
            'old' => ['permissions' => [Permission::DepartmentsView->value]],
        ]);
    });

    it('logs nothing when a role is saved unchanged', function () {
        $manager = userWithPermissions(Permission::RolesManage);
        $role = Role::findByName('viewer')->syncPermissions([Permission::DepartmentsView->value]);

        $this->actingAs($manager)->put(route('admin.roles.update', $role), [
            'name' => 'viewer',
            'permissions' => [Permission::DepartmentsView->value],
        ])->assertSessionHasNoErrors();

        expect(activitiesWithEvent('updated', 'role'))->toBeEmpty();
    });

    it('keeps the name and permissions of a deleted role', function () {
        $manager = userWithPermissions(Permission::RolesManage);
        $role = Role::findByName('viewer')->syncPermissions([Permission::DepartmentsView->value]);

        $this->actingAs($manager)->delete(route('admin.roles.destroy', $role))
            ->assertInertiaFlash('toast.type', 'success');

        [$activity] = activitiesWithEvent('deleted', 'role');
        expect($activity)
            ->subject_type->toBe('role')
            ->subject_id->toBe($role->id)
            ->and($activity->attribute_changes->get('old'))->toBe([
                'name' => 'viewer',
                'permissions' => [Permission::DepartmentsView->value],
            ]);
    });
});
