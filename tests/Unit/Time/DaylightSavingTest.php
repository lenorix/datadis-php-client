<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Time\HourLabel;
use Lenorix\DatadisClient\Time\QuarterHourLabel;
use Lenorix\DatadisClient\Time\TimeInstant;

$madrid = new DateTimeZone('Europe/Madrid');
$canary = new DateTimeZone('Atlantic/Canary');
$utc = fn (?DateTimeImmutable $d) => $d?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');

it('gives each repeated 03:00 of the autumn day its own hour', function () use ($madrid, $utc) {
    $day = new DateTimeImmutable('2025-10-26', $madrid);
    $first = HourLabel::parse('03:00')->interval($day, 0);
    $second = HourLabel::parse('03:00')->interval($day, 1);

    expect(array_map($utc, $first))->toBe(['2025-10-26 00:00', '2025-10-26 01:00'])
        ->and(array_map($utc, $second))->toBe(['2025-10-26 01:00', '2025-10-26 02:00'])
        ->and(HourLabel::parse('03:00')->interval($day, 2))->toBeNull();
});

it('keeps the other autumn hours one hour wide', function (string $label, string $start) use ($madrid, $utc) {
    [$from, $to] = HourLabel::parse($label)->interval(new DateTimeImmutable('2025-10-26', $madrid));

    expect($utc($from))->toBe($start)->and($to->getTimestamp() - $from->getTimestamp())->toBe(3600);
})->with([
    ['01:00', '2025-10-25 22:00'],
    ['02:00', '2025-10-25 23:00'],
    ['04:00', '2025-10-26 02:00'],
    ['24:00', '2025-10-26 22:00'],
]);

it('has no interval for the hour the spring change skips', function () use ($madrid, $utc) {
    $day = new DateTimeImmutable('2026-03-29', $madrid);

    expect(HourLabel::parse('03:00')->interval($day))->toBeNull()
        ->and(array_map($utc, HourLabel::parse('02:00')->interval($day)))->toBe(['2026-03-29 00:00', '2026-03-29 01:00'])
        ->and(array_map($utc, HourLabel::parse('04:00')->interval($day)))->toBe(['2026-03-29 01:00', '2026-03-29 02:00']);
});

it('repeats 02:00 in the Canary Islands, where the change happens an hour earlier on the wall clock', function () use ($canary, $utc) {
    $day = new DateTimeImmutable('2025-10-26', $canary);

    expect(array_map($utc, HourLabel::parse('02:00')->interval($day, 0)))->toBe(['2025-10-26 00:00', '2025-10-26 01:00'])
        ->and(array_map($utc, HourLabel::parse('02:00')->interval($day, 1)))->toBe(['2025-10-26 01:00', '2025-10-26 02:00'])
        ->and(HourLabel::parse('03:00')->interval($day, 1))->toBeNull();
});

it('handles quarter hours on both change days', function () use ($madrid, $utc) {
    $autumn = new DateTimeImmutable('2025-10-26', $madrid);
    $spring = new DateTimeImmutable('2026-03-29', $madrid);

    expect(array_map($utc, QuarterHourLabel::parse('03:00')->interval($autumn, 0)))->toBe(['2025-10-26 00:45', '2025-10-26 01:00'])
        ->and(array_map($utc, QuarterHourLabel::parse('03:00')->interval($autumn, 1)))->toBe(['2025-10-26 01:45', '2025-10-26 02:00'])
        ->and(QuarterHourLabel::parse('02:15')->interval($spring))->toBeNull()
        ->and(QuarterHourLabel::parse('03:00')->interval($spring))->toBeNull()
        ->and(array_map($utc, QuarterHourLabel::parse('03:15')->interval($spring)))->toBe(['2026-03-29 01:00', '2026-03-29 01:15']);
});

it('reads an instant in the repeated hour as its first occurrence and refuses one in the skipped hour', function () use ($madrid, $utc) {
    expect($utc(TimeInstant::tryParse('2025/10/26', '02:30', $madrid)))->toBe('2025-10-26 00:30')
        ->and(TimeInstant::tryParse('2026/03/29', '02:30', $madrid))->toBeNull()
        ->and($utc(TimeInstant::tryParse('2026/03/29', '03:00', $madrid)))->toBe('2026-03-29 01:00');
});

it('works with a fixed offset zone', function () use ($utc) {
    $day = new DateTimeImmutable('2025-10-26', new DateTimeZone('+01:00'));

    expect(array_map($utc, HourLabel::parse('03:00')->interval($day)))->toBe(['2025-10-26 01:00', '2025-10-26 02:00'])
        ->and(HourLabel::parse('03:00')->interval($day, 1))->toBeNull();
});
