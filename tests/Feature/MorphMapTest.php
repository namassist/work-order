<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Run the morph alias migration in the given direction.
 */
function runMorphAliasMigration(string $direction): void
{
    $migration = require database_path('migrations/2026_09_25_073752_use_morph_aliases_in_permission_pivots.php');

    $migration->{$direction}();
}

it('stores role and permission holders under the short alias', function () {
    $user = adminUser()->givePermissionTo(Permission::UsersView->value);

    $this->assertDatabaseHas('model_has_roles', ['model_id' => $user->id, 'model_type' => 'user']);
    $this->assertDatabaseHas('model_has_permissions', ['model_id' => $user->id, 'model_type' => 'user']);
});

it('rejects polymorphic use of a model missing from the morph map', function () {
    (new class extends Model {})->getMorphClass();
})->throws(ClassMorphViolationException::class);

it('rewrites existing class names to aliases and keeps permission checks working', function () {
    adminUser();
    $user = User::factory()->create();
    DB::table('model_has_roles')->insert([
        'role_id' => Role::findByName(SystemRole::Admin->value)->id,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);
    DB::table('model_has_permissions')->insert([
        'permission_id' => DB::table('permissions')->where('name', Permission::UsersView->value)->value('id'),
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);

    runMorphAliasMigration('up');

    expect(DB::table('model_has_roles')->where('model_type', User::class)->exists())->toBeFalse()
        ->and(DB::table('model_has_permissions')->where('model_type', User::class)->exists())->toBeFalse();
    expect($user->fresh())
        ->hasRole(SystemRole::Admin->value)->toBeTrue()
        ->checkPermissionTo(Permission::RolesManage->value)->toBeTrue();
});

it('restores class names when rolled back', function () {
    $user = adminUser();

    runMorphAliasMigration('down');

    $this->assertDatabaseHas('model_has_roles', ['model_id' => $user->id, 'model_type' => User::class]);
    $this->assertDatabaseMissing('model_has_roles', ['model_type' => 'user']);
});
