<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * Several processes, each with its own database connection, number work
 * orders in the same scope at once. They must commit for the others to see
 * their counter, so they run outside this test's transaction against the
 * real test database, and the test removes their counter afterwards.
 */

const CONCURRENT_PROCESSES = 8;
const NUMBERS_PER_PROCESS = 25;

it('never hands out the same number twice under concurrent submissions', function () {
    $connection = DB::connection();

    if (! in_array($connection->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Needs a shared PostgreSQL or MySQL database; '.$connection->getDriverName().' cannot run concurrent writers.');
    }

    $departmentCode = 'CC'.Str::upper(Str::random(6));
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection->getName(),
        'DB_URL' => '',
        'DB_HOST' => (string) $connection->getConfig('host'),
        'DB_PORT' => (string) $connection->getConfig('port'),
        'DB_DATABASE' => $connection->getDatabaseName(),
        'DB_USERNAME' => (string) $connection->getConfig('username'),
        'DB_PASSWORD' => (string) $connection->getConfig('password'),
    ];
    $command = [PHP_BINARY, base_path('tests/Fixtures/generate-work-order-numbers.php'), $departmentCode, (string) NUMBERS_PER_PROCESS];

    try {
        $results = Process::pool(function ($pool) use ($command, $env): void {
            foreach (range(1, CONCURRENT_PROCESSES) as $index) {
                $pool->as("worker-{$index}")->env($env)->timeout(120)->command($command);
            }
        })->start()->wait();

        $numbers = [];
        foreach ($results as $name => $result) {
            expect($result->successful())->toBeTrue("{$name} failed: ".$result->errorOutput());
            array_push($numbers, ...array_filter(explode(PHP_EOL, trim($result->output()))));
        }

        $total = CONCURRENT_PROCESSES * NUMBERS_PER_PROCESS;
        $sequences = array_map(fn (string $number): int => (int) Str::afterLast($number, '/'), $numbers);
        sort($sequences);

        expect($numbers)->toHaveCount($total)
            ->and(array_unique($numbers))->toHaveCount($total)
            ->and($sequences)->toBe(range(1, $total));
    } finally {
        // A second connection deletes outside the test transaction, which would roll the delete back.
        config(['database.connections.concurrency_cleanup' => $connection->getConfig()]);
        DB::connection('concurrency_cleanup')->table('work_order_number_sequences')
            ->where('scope', 'like', "%/{$departmentCode}/%")
            ->delete();
        DB::purge('concurrency_cleanup');
    }
})->group('concurrency');
