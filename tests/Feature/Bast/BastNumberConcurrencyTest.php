<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * Several processes, each with its own database connection, number BASTs in
 * the same scope at once (FLOW.md §8). They must commit for the others to
 * see their counter, so they run outside this test's transaction against
 * the real test database, and the test removes their counter afterwards.
 */

it('never hands out the same BAST number twice under concurrent submissions', function () {
    $processes = 8;
    $perProcess = 25;
    $connection = DB::connection();
    $env = concurrentProcessEnv();
    $departmentCode = 'CB'.Str::upper(Str::random(6));
    $command = [PHP_BINARY, base_path('tests/Fixtures/generate-bast-numbers.php'), $departmentCode, (string) $perProcess];

    try {
        $results = Process::pool(function ($pool) use ($command, $env, $processes): void {
            foreach (range(1, $processes) as $index) {
                $pool->as("worker-{$index}")->env($env)->timeout(120)->command($command);
            }
        })->start()->wait();

        $numbers = [];
        foreach ($results as $name => $result) {
            expect($result->successful())->toBeTrue("{$name} failed: ".$result->errorOutput());
            array_push($numbers, ...array_filter(explode(PHP_EOL, trim($result->output()))));
        }

        $total = $processes * $perProcess;
        $sequences = array_map(fn (string $number): int => (int) Str::afterLast($number, '/'), $numbers);
        sort($sequences);

        expect($numbers)->toHaveCount($total)
            ->and(array_unique($numbers))->toHaveCount($total)
            ->and($numbers[0])->toStartWith("BAST/{$departmentCode}/")
            ->and($sequences)->toBe(range(1, $total));
    } finally {
        // A second connection deletes outside the test transaction, which would roll the delete back.
        config(['database.connections.concurrency_cleanup' => $connection->getConfig()]);
        DB::connection('concurrency_cleanup')->table('bast_number_sequences')
            ->where('scope', 'like', "BAST/{$departmentCode}/%")
            ->delete();
        DB::purge('concurrency_cleanup');
    }
})->group('concurrency');
