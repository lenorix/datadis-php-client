<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Time\HourLabel;

$madrid = new DateTimeZone('Europe/Madrid');

it('maps end-of-interval labels 01:00..24:00 to hour indexes 0..23', function () {
    expect(HourLabel::tryParse('01:00')?->index())->toBe(0)
        ->and(HourLabel::tryParse('02:00')?->index())->toBe(1)
        ->and(HourLabel::tryParse('24:00')?->index())->toBe(23);
});

it('rejects everything that is not a strict hourly label', function (string $value) {
    expect(HourLabel::tryParse($value))->toBeNull();
})->with(['00:00', '25:00', '1:00', '01:30', '24:01', '01:00 ', '', '01-00', '1', 'ab:cd', '001:00']);

it('throws from parse() on invalid labels', function () {
    HourLabel::parse('00:00');
})->throws(InvalidArgumentException::class);

it('computes the interval on a normal day', function () use ($madrid) {
    $first = HourLabel::parse('01:00')->interval(new DateTimeImmutable('2025-01-15 00:00', $madrid));
    $last = HourLabel::parse('24:00')->interval(new DateTimeImmutable('2025-01-15 00:00', $madrid));

    expect($first[0]->format('Y-m-d H:i'))->toBe('2025-01-15 00:00')
        ->and($first[1]->format('Y-m-d H:i'))->toBe('2025-01-15 01:00')
        ->and($last[0]->format('Y-m-d H:i'))->toBe('2025-01-15 23:00')
        ->and($last[1]->format('Y-m-d H:i'))->toBe('2025-01-16 00:00');
});

it('treats 24:00 of the last day of the year as the next year midnight', function () use ($madrid) {
    [, $end] = HourLabel::parse('24:00')->interval(new DateTimeImmutable('2025-12-31', $madrid));

    expect($end->format('Y-m-d H:i'))->toBe('2026-01-01 00:00');
});

it('uses the wall clock and ignores the time of day of the given date', function () use ($madrid) {
    [$start] = HourLabel::parse('03:00')->interval(new DateTimeImmutable('2025-01-15 17:45', $madrid));

    expect($start->format('Y-m-d H:i'))->toBe('2025-01-15 02:00');
});

it('refuses a negative occurrence instead of answering as if the hour never happened', function () use ($madrid) {
    HourLabel::parse('05:00')->interval(new DateTimeImmutable('2025-01-15', $madrid), -1);
})->throws(InvalidArgumentException::class, 'occurrence');
