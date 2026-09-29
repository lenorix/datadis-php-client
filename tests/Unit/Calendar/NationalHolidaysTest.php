<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Calendar\NationalHolidays;

it('knows the fixed-date national holidays that count for tariff periods', function (string $date) {
    expect(NationalHolidays::isHoliday(new DateTimeImmutable($date)))->toBeTrue();
})->with(['2026-01-01', '2026-01-06', '2026-05-01', '2026-08-15', '2026-10-12', '2026-11-01', '2026-12-06', '2026-12-08', '2026-12-25']);

it('does not count movable holidays such as Good Friday', function () {
    expect(NationalHolidays::isHoliday(new DateTimeImmutable('2026-04-03')))->toBeFalse()
        ->and(NationalHolidays::isWorkingDay(new DateTimeImmutable('2026-04-03')))->toBeTrue();
});

it('treats weekends and holidays as non-working days', function () {
    expect(NationalHolidays::isWorkingDay(new DateTimeImmutable('2026-09-26')))->toBeFalse()
        ->and(NationalHolidays::isWorkingDay(new DateTimeImmutable('2026-09-27')))->toBeFalse()
        ->and(NationalHolidays::isWorkingDay(new DateTimeImmutable('2026-10-12')))->toBeFalse()
        ->and(NationalHolidays::isWorkingDay(new DateTimeImmutable('2026-09-28')))->toBeTrue();
});

it('reads the civil date of the instant in its own time zone', function () {
    $newYearInCanarias = new DateTimeImmutable('2026-01-01 00:30', new DateTimeZone('Atlantic/Canary'));

    expect(NationalHolidays::isHoliday($newYearInCanarias))->toBeTrue();
});
