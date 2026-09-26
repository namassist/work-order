<?php

/**
 * Child process for WorkOrderNumberConcurrencyTest: boots the application and
 * prints one freshly generated number per line.
 *
 * Usage: php generate-work-order-numbers.php <department code> <count>
 */

use App\Support\WorkOrderNumberGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $departmentCode, $count] = $argv;
$generator = $app->make(WorkOrderNumberGenerator::class);

for ($i = 0; $i < (int) $count; $i++) {
    echo DB::transaction(fn (): string => $generator->next($departmentCode, now())), PHP_EOL;
}
