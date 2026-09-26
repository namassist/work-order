<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Hands out work order numbers in the configured format
 * (config work_order.number_format).
 *
 * The format with every token but {SEQ} filled in is the counter's scope, so
 * the default format counts per department per month. The counter row is
 * locked for the rest of the caller's transaction: concurrent submissions in
 * one scope wait for each other, and a rolled-back submission gives its
 * number back.
 */
class WorkOrderNumberGenerator
{
    private const string SEQUENCE_PATTERN = '/\{SEQ(?::(\d+))?\}/';

    /**
     * The next number for a department at the given moment (in the display
     * timezone). Must run inside the transaction that stores the number.
     */
    public function next(string $departmentCode, CarbonInterface $at): string
    {
        $format = config()->string('work_order.number_format');

        if (preg_match(self::SEQUENCE_PATTERN, $format) !== 1) {
            throw new InvalidArgumentException('work_order.number_format must contain a {SEQ} or {SEQ:n} token.');
        }

        $local = DisplayDate::local($at);

        $scope = strtr($format, [
            '{DEPT_CODE}' => $departmentCode,
            '{YYYY}' => $local->format('Y'),
            '{YY}' => $local->format('y'),
            '{MM}' => $local->format('m'),
        ]);

        $sequence = $this->increment($scope);

        return (string) preg_replace_callback(
            self::SEQUENCE_PATTERN,
            fn (array $match): string => str_pad((string) $sequence, (int) ($match[1] ?? 1), '0', STR_PAD_LEFT),
            $scope,
        );
    }

    /**
     * Increment the scope's counter under a row lock and return the new value.
     */
    private function increment(string $scope): int
    {
        return DB::transaction(function () use ($scope): int {
            $now = now();

            DB::table('work_order_number_sequences')->insertOrIgnore([
                'scope' => $scope,
                'last_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $current = (int) DB::table('work_order_number_sequences')
                ->where('scope', $scope)
                ->lockForUpdate()
                ->value('last_value');

            DB::table('work_order_number_sequences')
                ->where('scope', $scope)
                ->update(['last_value' => $current + 1, 'updated_at' => $now]);

            return $current + 1;
        });
    }
}
