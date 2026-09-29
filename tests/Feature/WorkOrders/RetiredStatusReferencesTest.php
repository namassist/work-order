<?php

use Symfony\Component\Finder\Finder;

/*
| FLOW.md v2 step 3 removed the v1 statuses Dikerjakan, Penagihan, and
| Selesai (and the PROVISIONAL sides of step 2). Nothing may refer to them
| any more. Only identifiers are searched: status names as string literals or
| object keys, the state classes, and enum cases; never plain words, since
| "selesai" is everyday Indonesian ("Target selesai").
|
| The one exception is the migration that refuses a database still holding
| them. Migrations before it are history and are not searched.
*/

const RETIRED_STATUS_MIGRATION = 'database/migrations/2026_09_29_034158_update_work_order_statuses_for_v2.php';

it('refers to no retired status or side anywhere', function () {
    $patterns = [
        // Status names as string literals: 'selesai', "penagihan", `dikerjakan`.
        '/[\'"`](dikerjakan|penagihan|selesai)[\'"`]/',
        // Status names as object or array keys: selesai: 1.
        '/\b(dikerjakan|penagihan|selesai)\s*:(?!:)/',
        // The state classes and their constants: Selesai::class, WorkOrder\Penagihan.
        '/\b(Dikerjakan|Penagihan|Selesai)::/',
        '/States\\\\WorkOrder\\\\(Dikerjakan|Penagihan|Selesai)\b/',
        // Enum cases.
        '/\bcase\s+(Dikerjakan|Penagihan|Selesai)\b/',
        // The PROVISIONAL sides of step 2.
        '/\bWorkOrderSide\b|\bisOnSide\b|\bWorkOrdersProcess\b/',
    ];

    $finder = Finder::create()
        ->files()
        ->in(array_map(base_path(...), ['app', 'bootstrap', 'config', 'database', 'lang', 'resources/js', 'routes', 'tests']))
        ->name(['*.php', '*.ts', '*.vue', '*.json'])
        ->exclude(['actions', 'routes', 'wayfinder'])
        ->notPath('Fixtures');

    $hits = [];

    foreach ($finder as $file) {
        $path = str_replace(base_path().'/', '', $file->getRealPath());

        if ($path === RETIRED_STATUS_MIGRATION
            || $path === 'tests/Feature/WorkOrders/RetiredStatusReferencesTest.php'
            || (str_starts_with($path, 'database/migrations/') && basename($path) < basename(RETIRED_STATUS_MIGRATION))) {
            continue;
        }

        foreach (explode("\n", $file->getContents()) as $number => $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $hits[] = "{$path}:".($number + 1).': '.trim($line);
                }
            }
        }
    }

    expect($hits)->toBe([]);
});

it('keeps the refusal of the v1 statuses in the step 3 migration', function () {
    expect(file_get_contents(base_path(RETIRED_STATUS_MIGRATION)))
        ->toContain("private const array RETIRED_STATUSES = ['dikerjakan', 'penagihan', 'selesai'];");
});
