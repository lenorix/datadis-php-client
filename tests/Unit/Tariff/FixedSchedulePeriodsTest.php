<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Calendar\Territory;
use Lenorix\DatadisClient\Tariff\FixedSchedulePeriods;

$workingDay = new DateTimeImmutable('2026-09-28');

it('follows the 2.0TD schedule on working days', function () use ($workingDay) {
    $periods = new FixedSchedulePeriods;
    $byHour = array_map(fn (int $h) => $periods->periodFor($workingDay, $h), range(0, 23));

    expect($byHour)->toBe([3, 3, 3, 3, 3, 3, 3, 3, 2, 2, 1, 1, 1, 1, 2, 2, 2, 2, 1, 1, 1, 1, 2, 2]);
});

it('shifts every block one hour later in Ceuta and Melilla', function (Territory $territory) use ($workingDay) {
    $periods = new FixedSchedulePeriods($territory);
    $byHour = array_map(fn (int $h) => $periods->periodFor($workingDay, $h), range(0, 23));

    expect($byHour)->toBe([3, 3, 3, 3, 3, 3, 3, 3, 2, 2, 2, 1, 1, 1, 1, 2, 2, 2, 2, 1, 1, 1, 1, 2]);
})->with([Territory::Ceuta, Territory::Melilla]);

it('uses the peninsular schedule for the islands', function (Territory $territory) use ($workingDay) {
    expect((new FixedSchedulePeriods($territory))->periodFor($workingDay, 10))->toBe(1)
        ->and((new FixedSchedulePeriods($territory))->periodFor($workingDay, 22))->toBe(2);
})->with([Territory::Baleares, Territory::Canarias, Territory::Peninsula]);

it('is all valley on weekends and national holidays', function (string $date) {
    $periods = new FixedSchedulePeriods;

    expect(array_unique(array_map(fn (int $h) => $periods->periodFor(new DateTimeImmutable($date), $h), range(0, 23))))->toBe([3]);
})->with(['2026-09-26', '2026-09-27', '2026-12-25']);

it('refuses an hour outside 0..23', function (int $hour) use ($workingDay) {
    (new FixedSchedulePeriods)->periodFor($workingDay, $hour);
})->with([-1, 24])->throws(InvalidArgumentException::class);
