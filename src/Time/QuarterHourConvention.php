<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

/**
 * How a quarter-hourly answer labels its quarters. No quarter-hourly answer of a point type 1, 2 or 3
 * supply has been captured. Datadis's own portal places quarter-hourly readings on the labels
 * `00:15` to `24:00`, the end of each quarter, so that is the convention taken when an answer does
 * not tell; the other one, which one implementation in production assumes, is still recognised.
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
     * `00:45`) only at the end of a quarter, and `24:15` to `24:45` only in the other. With neither,
     * the end of the quarter, as Datadis's portal reads them. Null when the labels have both, which
     * no single convention explains, or no quarter at all (only whole hours): an hourly answer read
     * as quarters would put an hour of energy on 15 minutes.
     *
     * @param  iterable<string>  $labels
     */
    public static function detect(iterable $labels): ?self
    {
        $quarterEnd = false;
        $hourEnding = false;
        $quarters = false;

        foreach ($labels as $label) {
            $quarterEnd = $quarterEnd || preg_match('/^00:(15|30|45)$/D', $label) === 1;
            $hourEnding = $hourEnding || preg_match('/^24:(15|30|45)$/D', $label) === 1;
            $quarters = $quarters || preg_match('/^\d{2}:(15|30|45)$/D', $label) === 1;
        }

        return match (true) {
            // Only whole hours (an hourly answer to a quarter-hourly query): no quarter to place.
            ! $quarters => null,
            $quarterEnd && ! $hourEnding => self::QuarterEnd,
            $hourEnding && ! $quarterEnd => self::HourEndingWithStartMinute,
            $quarterEnd => null,
            default => self::QuarterEnd,
        };
    }
}
