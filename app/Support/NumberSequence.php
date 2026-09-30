<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Renders a configurable document number such as WO/{DEPT_CODE}/{YYYY}/{MM}/{SEQ:4}
 * from a counter table (work order and BAST numbers).
 *
 * The format with every token but {SEQ} filled in is the counter's scope, so
 * a format with {MM} counts per month. The counter row is locked for the
 * rest of the caller's transaction: concurrent callers in one scope wait for
 * each other, and a rolled-back caller gives its number back.
 */
final readonly class NumberSequence
{
    private const string SEQUENCE_PATTERN = '/\{SEQ(?::(\d+))?\}/';

    /**
     * @param  string  $table  the counter table (scope, last_value, timestamps)
     * @param  string  $configKey  the config key holding the format, named in errors
     */
    public function __construct(private string $table, private string $configKey) {}

    /**
     * The next number at the given moment (in the display timezone). Must
     * run inside the transaction that stores the number.
     */
    public function next(string $departmentCode, CarbonInterface $at): string
    {
        $format = config()->string($this->configKey);

        if (preg_match(self::SEQUENCE_PATTERN, $format) !== 1) {
            throw new InvalidArgumentException("{$this->configKey} must contain a {SEQ} or {SEQ:n} token.");
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

            DB::table($this->table)->insertOrIgnore([
                'scope' => $scope,
                'last_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $current = (int) DB::table($this->table)
                ->where('scope', $scope)
                ->lockForUpdate()
                ->value('last_value');

            DB::table($this->table)
                ->where('scope', $scope)
                ->update(['last_value' => $current + 1, 'updated_at' => $now]);

            return $current + 1;
        });
    }
}
