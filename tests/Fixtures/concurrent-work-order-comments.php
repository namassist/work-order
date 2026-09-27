<?php

/**
 * Child process for WorkOrderCommentConcurrencyTest. Its rows commit, so the
 * test's other processes see them; the test calls `cleanup` afterwards.
 *
 * Usage:
 *   php concurrent-work-order-comments.php setup
 *       creates a submitted work order and a commenter, prints "<work order id> <user id>"
 *   php concurrent-work-order-comments.php comment <work order id> <user id> <count>
 *       posts <count> comments through AddWorkOrderComment
 *   php concurrent-work-order-comments.php cleanup <work order id> <user id> <first activity id>
 *       deletes everything setup and comment created
 */

use App\Actions\WorkOrders\AddWorkOrderComment;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';

if ($mode === 'setup') {
    $workOrder = WorkOrder::factory()->submitted()->create();
    $commenter = User::factory()->create(['department_id' => $workOrder->requester_department_id]);

    echo $workOrder->id, ' ', $commenter->id, PHP_EOL;
    exit(0);
}

if ($mode === 'comment') {
    [, , $workOrderId, $userId, $count] = $argv;
    $workOrder = WorkOrder::query()->findOrFail((int) $workOrderId);
    $commenter = User::query()->findOrFail((int) $userId);
    $comments = $app->make(AddWorkOrderComment::class);

    try {
        for ($i = 1; $i <= (int) $count; $i++) {
            $comments->handle($workOrder, $commenter, "Komentar {$i}");
        }
    } catch (Throwable $exception) {
        // Laravel's handler would report the exception and still exit 0.
        fwrite(STDERR, $exception->getMessage().PHP_EOL);
        exit(1);
    }
    exit(0);
}

if ($mode === 'cleanup') {
    [, , $workOrderId, $userId, $firstActivityId] = $argv;
    $workOrder = WorkOrder::withTrashed()->findOrFail((int) $workOrderId);
    $users = User::withTrashed()->whereKey([(int) $userId, $workOrder->created_by])->get();

    DB::transaction(function () use ($workOrder, $users, $firstActivityId): void {
        Activity::query()->where('id', '>=', (int) $firstActivityId)->delete();
        // Comments and status histories cascade.
        DB::table('work_orders')->where('id', $workOrder->id)->delete();
        DB::table('users')->whereIn('id', $users->modelKeys())->delete();
        Department::withTrashed()->whereKey([$workOrder->requester_department_id, ...$users->pluck('department_id')->filter()])->forceDelete();
        WorkOrderCategory::withTrashed()->whereKey($workOrder->work_order_category_id)->forceDelete();
    });
    exit(0);
}

fwrite(STDERR, "Unknown mode: {$mode}".PHP_EOL);
exit(1);
