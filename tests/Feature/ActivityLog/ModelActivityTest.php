<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrderCategory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * The latest activity recorded for the model.
 */
function latestActivityFor(Model $model): ?Activity
{
    return Activity::query()->forSubject($model)->latest('id')->first();
}

dataset('logged models', [
    'user' => [fn (): User => User::factory()->create(['name' => 'Lama']), 'user'],
    'department' => [fn (): Department => Department::factory()->create(['name' => 'Lama']), 'department'],
    'work order category' => [fn (): WorkOrderCategory => WorkOrderCategory::factory()->create(['name' => 'Lama']), 'wo-category'],
]);

it('logs creation with the tracked attributes under the model alias', function (Closure $create, string $alias) {
    $model = $create();

    expect(latestActivityFor($model))
        ->log_name->toBe('audit')
        ->event->toBe('created')
        ->subject_type->toBe($alias)
        ->and(latestActivityFor($model)->attribute_changes->get('attributes'))->toMatchArray(['name' => 'Lama']);
})->with('logged models');

it('logs only the attributes that changed on update', function (Closure $create) {
    $model = $create();

    $model->update(['name' => 'Baru']);

    expect(latestActivityFor($model)->event)->toBe('updated')
        ->and(latestActivityFor($model)->attribute_changes->all())->toBe([
            'attributes' => ['name' => 'Baru'],
            'old' => ['name' => 'Lama'],
        ]);
})->with('logged models');

it('logs nothing when no tracked attribute changed', function (Closure $create) {
    $model = $create();

    $model->touch();

    expect(Activity::query()->forSubject($model)->pluck('event')->all())->toBe(['created']);
})->with('logged models');

it('logs soft delete and restore', function (Closure $create) {
    $model = $create();

    $model->delete();
    $model->restore();

    expect(Activity::query()->forSubject($model)->orderBy('id')->pluck('event')->all())
        ->toBe(['created', 'deleted', 'restored']);
})->with('logged models');

it('names activation changes as activated and deactivated', function (Closure $create) {
    $model = $create();

    $model->update(['is_active' => false]);
    $deactivated = latestActivityFor($model);
    $model->update(['is_active' => true]);

    expect($deactivated->event)->toBe('deactivated')
        ->and($deactivated->attribute_changes->get('old'))->toBe(['is_active' => true])
        ->and(latestActivityFor($model)->event)->toBe('activated');
})->with('logged models');

it('records the signed-in user as the causer', function () {
    $admin = userWithPermissions(Permission::DepartmentsUpdate);
    $department = Department::factory()->create(['code' => 'FIN']);

    $this->actingAs($admin)
        ->put(route('admin.departments.update', $department), ['code' => 'FIN', 'name' => 'Finance', 'is_active' => true]);

    expect(latestActivityFor($department))
        ->event->toBe('updated')
        ->causer_type->toBe('user')
        ->causer_id->toBe($admin->id);
});

it('never logs user secrets or password-only changes', function () {
    $user = User::factory()->mustChangePassword()->create(['password' => 'rahasia-awal']);

    $user->update(['password' => 'rahasia-baru', 'must_change_password' => false]);
    $user->forceFill(['remember_token' => 'token-rahasia'])->save();

    $activities = Activity::query()->forSubject($user)->get();
    expect($activities->pluck('event')->all())->toBe(['created'])
        ->and($activities->first()->attribute_changes->get('attributes'))
        ->not->toHaveKeys(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])
        ->toMatchArray(['must_change_password' => true]);
    expect(Activity::query()->pluck('attribute_changes')->toJson())
        ->not->toContain('rahasia')
        ->not->toContain($user->password);
});
