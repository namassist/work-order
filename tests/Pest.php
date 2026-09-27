<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
 * Create a user holding exactly the given permissions (granted directly), in
 * a department of a new executor company (Unggul).
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
 * Create a user of a new client company (IC) department holding exactly the
 * given permissions.
 */
function icUser(Permission ...$permissions): User
{
    return userInDepartment(Department::factory()->client()->create(), ...$permissions);
}

/**
 * Create a user of a new executor company (Unggul) department holding
 * exactly the given permissions.
 */
function unggulUser(Permission ...$permissions): User
{
    return userInDepartment(Department::factory()->create(), ...$permissions);
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

/**
 * Environment for a child process that shares this test's database, so
 * several processes can write at once (see the `concurrency` group). Skips
 * the test on a database that cannot run concurrent writers.
 *
 * @return array<string, string>
 */
function concurrentProcessEnv(): array
{
    $connection = DB::connection();

    if (! in_array($connection->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
        test()->markTestSkipped('Needs a shared PostgreSQL or MySQL database; '.$connection->getDriverName().' cannot run concurrent writers.');
    }

    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection->getName(),
        'DB_URL' => '',
        'DB_HOST' => (string) $connection->getConfig('host'),
        'DB_PORT' => (string) $connection->getConfig('port'),
        'DB_DATABASE' => $connection->getDatabaseName(),
        'DB_USERNAME' => (string) $connection->getConfig('username'),
        'DB_PASSWORD' => (string) $connection->getConfig('password'),
    ];
}
