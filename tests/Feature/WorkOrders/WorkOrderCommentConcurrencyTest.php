<?php

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

/*
 * Several processes comment on the same work order at once. Each comment
 * locks the work order and touches it, so the lock must be an update lock:
 * two shared locks upgraded at once deadlock. The processes commit, so they
 * run outside this test's transaction and the test removes their rows.
 */

it('posts every comment when several users comment on one work order at once', function () {
    $env = concurrentProcessEnv();
    $script = base_path('tests/Fixtures/concurrent-work-order-comments.php');
    $processes = 6;
    $commentsPerProcess = 10;
    $firstActivityId = (int) Activity::query()->max('id') + 1;

    $setup = Process::env($env)->run([PHP_BINARY, $script, 'setup'])->throw();
    [$workOrderId, $userId] = explode(' ', trim($setup->output()));

    try {
        $results = Process::pool(function ($pool) use ($script, $env, $workOrderId, $userId, $processes, $commentsPerProcess): void {
            foreach (range(1, $processes) as $index) {
                $pool->as("worker-{$index}")->env($env)->timeout(120)
                    ->command([PHP_BINARY, $script, 'comment', $workOrderId, $userId, (string) $commentsPerProcess]);
            }
        })->start()->wait();

        foreach ($results as $name => $result) {
            expect($result->successful())->toBeTrue("{$name} failed: ".$result->errorOutput());
        }

        expect(WorkOrder::query()->findOrFail((int) $workOrderId)->comments()->count())->toBe($processes * $commentsPerProcess);
    } finally {
        Process::env($env)->run([PHP_BINARY, $script, 'cleanup', $workOrderId, $userId, (string) $firstActivityId])->throw();
    }
})->group('concurrency');
