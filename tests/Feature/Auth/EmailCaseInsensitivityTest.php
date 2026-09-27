<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->company = Company::factory()->client()->create(['email_domains' => ['ic.test']]);
    $this->department = Department::factory()->for($this->company)->create();
    $this->rani = User::factory()->for($this->department)->create(['email' => 'rani@ic.test']);
});

/**
 * The migration that made emails case-insensitive.
 */
function emailCaseMigration(): Migration
{
    return require database_path('migrations/2026_09_27_215008_make_user_emails_case_insensitive.php');
}

describe('one address, one account', function () {
    it('stores every email trimmed and in lowercase', function () {
        $user = User::factory()->create(['email' => '  Budi.Santoso@IC.Test ']);

        expect($user->refresh()->email)->toBe('budi.santoso@ic.test');

        $user->update(['email' => 'BUDI@ic.test']);
        expect($user->refresh()->email)->toBe('budi@ic.test');
    });

    it('refuses another casing through registration', function () {
        $this->post(route('register.store'), [
            'name' => 'Rani Lain',
            'email' => 'Rani@IC.test',
            'password' => 'Rahasia-Kuat-2026',
            'password_confirmation' => 'Rahasia-Kuat-2026',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
        ])->assertSessionHasErrors('email');

        expect(User::count())->toBe(1);
    });

    it('refuses another casing when an admin creates or updates a user', function () {
        config(['auth.default_user_password' => 'Rahasia#2026']);
        $admin = adminUser();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => 'Rani Lain', 'email' => 'Rani@ic.test', 'department_id' => $this->department->id])
            ->assertSessionHasErrors('email');

        $budi = User::factory()->for($this->department)->create(['email' => 'budi@ic.test']);
        $this->actingAs($admin)
            ->put(route('admin.users.update', $budi), ['name' => 'Budi', 'email' => 'RANI@ic.test', 'department_id' => $this->department->id, 'is_active' => true])
            ->assertSessionHasErrors('email');

        expect($budi->refresh()->email)->toBe('budi@ic.test');
    });

    it('refuses another casing on the profile page, and keeps the own address in any casing', function () {
        $budi = User::factory()->for($this->department)->create(['email' => 'budi@ic.test']);

        $this->actingAs($budi)->patch(route('profile.update'), ['name' => 'Budi', 'email' => 'Rani@IC.TEST'])->assertSessionHasErrors('email');
        $this->actingAs($budi)->patch(route('profile.update'), ['name' => 'Budi', 'email' => 'Budi@IC.test'])->assertSessionHasNoErrors();

        expect($budi->refresh()->email)->toBe('budi@ic.test');
    });

    it('refuses another casing of a deleted account', function () {
        $this->rani->delete();

        $this->actingAs(adminUser())
            ->post(route('admin.users.store'), ['name' => 'Rani Baru', 'email' => 'RANI@ic.test', 'department_id' => $this->department->id])
            ->assertSessionHasErrors('email');
    });

    it('is backed by a unique index on lower(email), even for raw writes', function () {
        expect(fn () => DB::table('users')->insert([
            'name' => 'Rani Mentah',
            'email' => 'Rani@IC.test',
            'password' => 'x',
            'department_id' => $this->department->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class, 'users_email_lower_unique');
    });
});

describe('lookup', function () {
    it('signs in with the email in any casing', function (string $email) {
        $this->post(route('login.store'), ['email' => $email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->rani);
    })->with(['rani@ic.test', 'Rani@IC.test', 'RANI@IC.TEST']);

    it('finds a user by email in any casing', function () {
        expect(User::query()->withEmail(' RANI@ic.Test ')->sole()->is($this->rani))->toBeTrue();
    });
});

describe('migration', function () {
    it('lowercases existing emails', function () {
        $migration = emailCaseMigration();
        $migration->down();
        DB::table('users')->where('id', $this->rani->id)->update(['email' => 'Rani@IC.test']);

        $migration->up();

        expect(DB::table('users')->where('id', $this->rani->id)->value('email'))->toBe('rani@ic.test');
    });

    it('refuses, naming the accounts, when two differ only in casing', function () {
        $migration = emailCaseMigration();
        $migration->down();
        $clone = User::factory()->for($this->department)->create(['email' => 'budi@ic.test']);
        DB::table('users')->where('id', $clone->id)->update(['email' => 'Rani@IC.test']);

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'rani@ic.test, Rani@IC.test');
        expect(DB::table('users')->where('id', $clone->id)->value('email'))->toBe('Rani@IC.test');
    });
});
