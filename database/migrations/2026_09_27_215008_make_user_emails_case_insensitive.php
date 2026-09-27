<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Emails are case-insensitive: stored lowercase (User::email() mutator) and
 * unique on lower(email), so "Rani@ic.test" and "rani@ic.test" can never be
 * two accounts. Existing emails are lowercased first; when two accounts
 * (deleted ones included) differ only in casing, the migration refuses and
 * names them, so an admin can merge or rename one before migrating again.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @throws RuntimeException when two accounts share an email in different casings
     */
    public function up(): void
    {
        $collisions = DB::table('users')
            ->selectRaw('lower(trim(email)) as email, string_agg(email, \', \' order by id) as variants')
            ->groupByRaw('lower(trim(email))')
            ->havingRaw('count(*) > 1')
            ->orderBy('email')
            ->get();

        if ($collisions->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot make user emails case-insensitive: these accounts differ only in casing. '
                .'Rename or remove one of each (deleted accounts count too), then migrate again: '
                .$collisions->map(fn (object $row): string => $row->variants)->implode('; '),
            );
        }

        DB::statement('UPDATE users SET email = lower(trim(email)) WHERE email <> lower(trim(email))');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_email_unique');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
    }

    /**
     * Reverse the migrations. Emails stay lowercase.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX users_email_lower_unique');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
    }
};
