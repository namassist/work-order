<?php

use App\Support\WorkOrderNumberGenerator;
use Illuminate\Support\Carbon;

function nextNumber(string $departmentCode, string $utc): string
{
    return app(WorkOrderNumberGenerator::class)->next($departmentCode, Carbon::parse($utc, 'UTC'));
}

it('counts per department and month in the default format', function () {
    expect([
        nextNumber('IT', '2026-09-10 02:00'),
        nextNumber('IT', '2026-09-11 02:00'),
        nextNumber('FIN', '2026-09-11 02:00'),
        nextNumber('IT', '2026-10-01 02:00'),
    ])->toBe([
        'WO/IT/2026/09/0001',
        'WO/IT/2026/09/0002',
        'WO/FIN/2026/09/0001',
        'WO/IT/2026/10/0001',
    ]);
});

it('takes the month in WITA', function () {
    // 30 Sep 16:30 UTC is 1 Oct 00:30 WITA.
    expect(nextNumber('IT', '2026-09-30 16:30'))->toBe('WO/IT/2026/10/0001');
});

it('follows a configured format', function () {
    config(['work_order.number_format' => '{YY}{MM}-{DEPT_CODE}-{SEQ:3}']);

    expect(nextNumber('IT', '2026-09-10 02:00'))->toBe('2609-IT-001')
        ->and(nextNumber('IT', '2026-09-10 03:00'))->toBe('2609-IT-002');
});

it('counts per year when the format has no month', function () {
    config(['work_order.number_format' => 'WO/{YYYY}/{SEQ:5}']);

    expect(nextNumber('IT', '2026-01-10 02:00'))->toBe('WO/2026/00001')
        ->and(nextNumber('FIN', '2026-12-10 02:00'))->toBe('WO/2026/00002');
});

it('grows past the padding instead of wrapping', function () {
    config(['work_order.number_format' => 'WO-{SEQ:1}']);

    expect(array_map(fn (): string => nextNumber('IT', '2026-09-10 02:00'), range(1, 10))[9])->toBe('WO-10');
});

it('rejects a format without a sequence token', function () {
    config(['work_order.number_format' => 'WO/{DEPT_CODE}/{YYYY}']);

    nextNumber('IT', '2026-09-10 02:00');
})->throws(InvalidArgumentException::class);
