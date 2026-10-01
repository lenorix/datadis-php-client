<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

/**
 * How a quarter-hourly answer labels its quarters. UNVERIFIED: no quarter-hourly answer of a point
 * type 1, 2 or 3 supply has been captured, so both possible conventions are understood.
 */
enum QuarterHourConvention
{
    /** The wall clock at the end of the quarter, `00:15` to `24:00`, like the hourly labels. */
    case QuarterEnd;

    /**
     * The hour that ends, like the hourly labels, followed by the minute the quarter starts at:
     * `01:00` is 00:00-00:15 and `24:45` is 23:45-24:00. One implementation used in production
     * reads quarter-hourly answers this way.
     */
    case HourEndingWithStartMinute;

    /**
     * The convention of an answer, from the labels only one of them has: hour `00` (`00:15` to
     * `00:45`) only at the end of a quarter, and `24:15` to `24:45` only in the other. Null when
     * the labels have neither, or both.
     *
     * @param  iterable<string>  $labels
     */
    public static function detect(iterable $labels): ?self
    {
        $quarterEnd = false;
        $hourEnding = false;

        foreach ($labels as $label) {
            $quarterEnd = $quarterEnd || preg_match('/^00:(15|30|45)$/D', $label) === 1;
            $hourEnding = $hourEnding || preg_match('/^24:(15|30|45)$/D', $label) === 1;
        }

        return match (true) {
            $quarterEnd && ! $hourEnding => self::QuarterEnd,
            $hourEnding && ! $quarterEnd => self::HourEndingWithStartMinute,
            default => null,
        };
    }
}
