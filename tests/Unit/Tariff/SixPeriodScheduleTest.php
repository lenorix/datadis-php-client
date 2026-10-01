<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Calendar\Territory;
use Lenorix\DatadisClient\Tariff\SixPeriodSchedule;

/*
 * Circular CNMC 3/2020, article 7.2, as in force (checked against the consolidated text of
 * BOE-A-2020-1066, last updated 2025): the six-period calendar of 3.0TD and 6.1TD to 6.4TD. Each
 * expected day is written out hour by hour (0 to 23) from the tables of the BOE.
 */

/** @return list<int> the period of every hour of the day */
function sixPeriodDay(Territory $territory, string $date): array
{
    $schedule = new SixPeriodSchedule($territory);
    $day = new DateTimeImmutable($date);

    return array_map(fn (int $hour) => $schedule->periodFor($day, $hour), range(0, 23));
}

it('follows the BOE table of every territory on a working day of every season', function (Territory $territory, string $date, array $expected) {
    expect(sixPeriodDay($territory, $date))->toBe($expected);
})->with([
    // Peninsula: high hours 9-14 and 18-22. High season Jan, Feb, Jul, Dec; medium-high Mar, Nov; medium Jun, Aug, Sep; low Apr, May, Oct.
    'Peninsula, high (A)' => [Territory::Peninsula, '2026-01-14', [6, 6, 6, 6, 6, 6, 6, 6, 2, 1, 1, 1, 1, 1, 2, 2, 2, 2, 1, 1, 1, 1, 2, 2]],
    'Peninsula, medium-high (B)' => [Territory::Peninsula, '2026-03-11', [6, 6, 6, 6, 6, 6, 6, 6, 3, 2, 2, 2, 2, 2, 3, 3, 3, 3, 2, 2, 2, 2, 3, 3]],
    'Peninsula, medium (B1)' => [Territory::Peninsula, '2026-06-10', [6, 6, 6, 6, 6, 6, 6, 6, 4, 3, 3, 3, 3, 3, 4, 4, 4, 4, 3, 3, 3, 3, 4, 4]],
    'Peninsula, low (C)' => [Territory::Peninsula, '2026-04-08', [6, 6, 6, 6, 6, 6, 6, 6, 5, 4, 4, 4, 4, 4, 5, 5, 5, 5, 4, 4, 4, 4, 5, 5]],
    // Illes Balears: high hours 10-15 and 18-22. High Jun-Sep; medium-high May, Oct; medium Jan, Feb, Dec; low Mar, Apr, Nov.
    'Baleares, high (A)' => [Territory::Baleares, '2026-06-10', [6, 6, 6, 6, 6, 6, 6, 6, 2, 2, 1, 1, 1, 1, 1, 2, 2, 2, 1, 1, 1, 1, 2, 2]],
    'Baleares, medium-high (B)' => [Territory::Baleares, '2026-05-13', [6, 6, 6, 6, 6, 6, 6, 6, 3, 3, 2, 2, 2, 2, 2, 3, 3, 3, 2, 2, 2, 2, 3, 3]],
    'Baleares, medium (B1)' => [Territory::Baleares, '2026-01-14', [6, 6, 6, 6, 6, 6, 6, 6, 4, 4, 3, 3, 3, 3, 3, 4, 4, 4, 3, 3, 3, 3, 4, 4]],
    'Baleares, low (C)' => [Territory::Baleares, '2026-03-11', [6, 6, 6, 6, 6, 6, 6, 6, 5, 5, 4, 4, 4, 4, 4, 5, 5, 5, 4, 4, 4, 4, 5, 5]],
    // Canarias: high hours 10-15 and 18-22, and its own periods per day type. High Jul-Oct; medium-high Nov, Dec; medium Jan-Mar; low Apr-Jun.
    'Canarias, high (A)' => [Territory::Canarias, '2026-07-08', [6, 6, 6, 6, 6, 6, 6, 6, 3, 3, 1, 1, 1, 1, 1, 3, 3, 3, 1, 1, 1, 1, 3, 3]],
    'Canarias, medium-high (B)' => [Territory::Canarias, '2026-11-11', [6, 6, 6, 6, 6, 6, 6, 6, 3, 3, 2, 2, 2, 2, 2, 3, 3, 3, 2, 2, 2, 2, 3, 3]],
    'Canarias, medium (B1)' => [Territory::Canarias, '2026-01-14', [6, 6, 6, 6, 6, 6, 6, 6, 4, 4, 2, 2, 2, 2, 2, 4, 4, 4, 2, 2, 2, 2, 4, 4]],
    'Canarias, low (C)' => [Territory::Canarias, '2026-04-08', [6, 6, 6, 6, 6, 6, 6, 6, 5, 5, 4, 4, 4, 4, 4, 5, 5, 5, 4, 4, 4, 4, 5, 5]],
    // Ceuta: high hours 10-15 and 19-23, and its own periods per day type. High Jan, Feb, Aug, Sep; medium-high Jul, Oct; medium Mar, Nov, Dec; low Apr-Jun.
    'Ceuta, high (A)' => [Territory::Ceuta, '2026-01-14', [6, 6, 6, 6, 6, 6, 6, 6, 4, 4, 1, 1, 1, 1, 1, 4, 4, 4, 4, 1, 1, 1, 1, 4]],
    'Ceuta, medium-high (B)' => [Territory::Ceuta, '2026-07-08', [6, 6, 6, 6, 6, 6, 6, 6, 3, 3, 2, 2, 2, 2, 2, 3, 3, 3, 3, 2, 2, 2, 2, 3]],
    'Ceuta, medium (B1)' => [Territory::Ceuta, '2026-03-11', [6, 6, 6, 6, 6, 6, 6, 6, 4, 4, 2, 2, 2, 2, 2, 4, 4, 4, 4, 2, 2, 2, 2, 4]],
    'Ceuta, low (C)' => [Territory::Ceuta, '2026-04-08', [6, 6, 6, 6, 6, 6, 6, 6, 5, 5, 3, 3, 3, 3, 3, 5, 5, 5, 5, 3, 3, 3, 3, 5]],
    // Melilla: high hours 10-15 and 19-23. High Jan, Jul, Aug, Sep; medium-high Feb, Dec; medium Jun, Oct, Nov; low Mar-May.
    'Melilla, high (A)' => [Territory::Melilla, '2026-01-14', [6, 6, 6, 6, 6, 6, 6, 6, 2, 2, 1, 1, 1, 1, 1, 2, 2, 2, 2, 1, 1, 1, 1, 2]],
    'Melilla, medium-high (B)' => [Territory::Melilla, '2026-02-11', [6, 6, 6, 6, 6, 6, 6, 6, 3, 3, 2, 2, 2, 2, 2, 3, 3, 3, 3, 2, 2, 2, 2, 3]],
    'Melilla, medium (B1)' => [Territory::Melilla, '2026-06-10', [6, 6, 6, 6, 6, 6, 6, 6, 4, 4, 3, 3, 3, 3, 3, 4, 4, 4, 4, 3, 3, 3, 3, 4]],
    'Melilla, low (C)' => [Territory::Melilla, '2026-03-11', [6, 6, 6, 6, 6, 6, 6, 6, 5, 5, 4, 4, 4, 4, 4, 5, 5, 5, 5, 4, 4, 4, 4, 5]],
]);

it('puts the months of the year in the season of each territory', function (Territory $territory, array $periodAtNoonByMonth) {
    $schedule = new SixPeriodSchedule($territory);
    $wednesdays = ['2026-01-14', '2026-02-11', '2026-03-11', '2026-04-08', '2026-05-13', '2026-06-10', '2026-07-08', '2026-08-12', '2026-09-09', '2026-10-14', '2026-11-11', '2026-12-09'];

    expect(array_map(fn (string $date) => $schedule->periodFor(new DateTimeImmutable($date), 12), $wednesdays))->toBe($periodAtNoonByMonth);
})->with([
    // Noon is a high hour everywhere: its period names the season (A=1, B=2, B1, C) for that territory.
    'Peninsula' => [Territory::Peninsula, [1, 1, 2, 4, 4, 3, 1, 3, 3, 4, 2, 1]],
    'Baleares' => [Territory::Baleares, [3, 3, 4, 4, 2, 1, 1, 1, 1, 2, 4, 3]],
    'Canarias' => [Territory::Canarias, [2, 2, 2, 4, 4, 4, 1, 1, 1, 1, 2, 2]],
    'Ceuta' => [Territory::Ceuta, [1, 1, 2, 3, 3, 3, 2, 1, 1, 2, 2, 2]],
    'Melilla' => [Territory::Melilla, [1, 2, 4, 4, 4, 3, 1, 1, 1, 3, 3, 2]],
]);

it('is all P6 on weekends, national holidays and 6 January', function (string $date) {
    expect(array_unique(sixPeriodDay(Territory::Peninsula, $date)))->toBe([6]);
})->with([
    'a Saturday' => ['2026-01-17'],
    'a Sunday' => ['2026-07-12'],
    'Epiphany' => ['2026-01-06'],
    'Christmas' => ['2026-12-25'],
    'Assumption, in high season' => ['2025-08-15'],
]);

it('refuses an hour outside 0..23', function (int $hour) {
    (new SixPeriodSchedule)->periodFor(new DateTimeImmutable('2026-01-14'), $hour);
})->with([-1, 24])->throws(InvalidArgumentException::class);
