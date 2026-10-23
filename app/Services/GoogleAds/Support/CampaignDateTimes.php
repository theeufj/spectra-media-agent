<?php

namespace App\Services\GoogleAds\Support;

use Carbon\CarbonImmutable;

/** Campaign timestamps are wall-clock values in the serving account's timezone. */
final class CampaignDateTimes
{
    public static function calendarDate(string $value): ?string
    {
        $value = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $value) ?? '';
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}):(\d{2}))?$/', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (isset($parts[4]) && ((int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59))) {
            return null;
        }

        return "{$parts[1]}-{$parts[2]}-{$parts[3]}";
    }

    public static function startOfDay(string $date): string
    {
        return self::requireDate($date).' 00:00:00';
    }

    public static function endOfDay(string $date): string
    {
        return self::requireDate($date).' 23:59:59';
    }

    /** Planless forecasts accept complete days, so exclude partial serving days. */
    public static function fullServingDay(string $value, bool $start): ?string
    {
        $date = self::calendarDate($value);
        if ($date === null) {
            return null;
        }
        $time = str_contains($value, ' ') ? substr($value, 11) : null;
        if ($time === null || $time === ($start ? '00:00:00' : '23:59:59')) {
            return $date;
        }

        $day = CarbonImmutable::parse($date, 'UTC');

        return $start ? $day->addDay()->toDateString() : $day->subDay()->toDateString();
    }

    private static function requireDate(string $date): string
    {
        $value = self::calendarDate($date);
        if ($value === null || str_contains($date, ' ')) {
            throw new \InvalidArgumentException('A valid campaign calendar date is required.');
        }

        return $value;
    }
}
