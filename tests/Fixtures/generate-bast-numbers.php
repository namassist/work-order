<?php

/**
 * Child process for BastNumberConcurrencyTest: boots the application and
 * prints one freshly generated BAST number per line, in a format scoped to
 * the given requester department code.
 *
 * Usage: php generate-bast-numbers.php <department code> <count>
 */

use App\Support\Bast\BastNumberGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $departmentCode, $count] = $argv;
config(['work_order.bast.number_format' => 'BAST/{DEPT_CODE}/{YYYY}/{MM}/{SEQ:4}']);
$generator = $app->make(BastNumberGenerator::class);

for ($i = 0; $i < (int) $count; $i++) {
    echo DB::transaction(fn (): string => $generator->next($departmentCode, now())), PHP_EOL;
}
