<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a user holding exactly the given permissions (granted directly).
 */
function userWithPermissions(Permission ...$permissions): User
{
    test()->seed(RolePermissionSeeder::class);

    return User::factory()->create()->givePermissionTo(
        array_map(fn (Permission $permission): string => $permission->value, $permissions),
    );
}

/**
 * Create a user with the seeded admin role.
 */
function adminUser(): User
{
    test()->seed(RolePermissionSeeder::class);

    return User::factory()->create()->assignRole(SystemRole::Admin->value);
}

/**
 * Create a user in the given department holding exactly the given permissions.
 */
function userInDepartment(Department $department, Permission ...$permissions): User
{
    $user = userWithPermissions(...$permissions);
    $user->update(['department_id' => $department->id]);

    return $user;
}

/**
 * Path of a file in tests/Fixtures/attachments.
 */
function attachmentFixture(string $name): string
{
    return __DIR__.'/Fixtures/attachments/'.$name;
}

/**
 * An upload with a fixture's content under any client filename, so a test
 * can disguise one type as another.
 */
function attachmentUpload(string $fixture, ?string $clientName = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($clientName ?? $fixture, (string) file_get_contents(attachmentFixture($fixture)));
}
