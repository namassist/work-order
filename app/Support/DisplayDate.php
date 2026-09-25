<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Converts between stored UTC timestamps and the display timezone
 * (config app.display_timezone). Output matches resources/js/lib/format.ts,
 * e.g. "25 Sep 2026 10:15".
 */
class DisplayDate
{
    /**
     * Indonesian month abbreviations as Intl (CLDR) writes them, so server and
     * browser agree; Carbon's own "id" locale uses "Agt" for August.
     *
     * @var array<int, string>
     */
    private const array MONTHS = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];

    public static function dateTime(CarbonInterface $moment): string
    {
        return self::date($moment).' '.self::local($moment)->format('H:i');
    }

    public static function date(CarbonInterface $moment): string
    {
        $local = self::local($moment);

        return $local->day.' '.self::MONTHS[$local->month].' '.$local->year;
    }

    /**
     * The first moment, in UTC, of a user-entered display-timezone day (Y-m-d).
     */
    public static function startOfDayUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, self::timezone())->startOfDay()->utc();
    }

    /**
     * The last moment, in UTC, of a user-entered display-timezone day (Y-m-d).
     */
    public static function endOfDayUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, self::timezone())->endOfDay()->utc();
    }

    public static function timezone(): string
    {
        return config()->string('app.display_timezone');
    }

    private static function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::timezone());
    }
}
