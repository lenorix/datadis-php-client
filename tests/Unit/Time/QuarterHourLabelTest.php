<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Time\QuarterHourLabel;

it('maps end-of-interval quarter labels to indexes 0..95', function () {
    expect(QuarterHourLabel::tryParse('00:15')?->index())->toBe(0)
        ->and(QuarterHourLabel::tryParse('01:00')?->index())->toBe(3)
        ->and(QuarterHourLabel::tryParse('24:00')?->index())->toBe(95);
});

it('rejects labels that are not on a quarter or are out of range', function (string $value) {
    expect(QuarterHourLabel::tryParse($value))->toBeNull();
})->with(['00:00', '00:10', '24:15', '25:00', '1:15', '12:60', '', '12:15 ']);

it('computes a 15 minute interval', function () {
    $day = new DateTimeImmutable('2025-01-15', new DateTimeZone('Europe/Madrid'));
    [$start, $end] = QuarterHourLabel::parse('09:45')->interval($day);

    expect($start->format('H:i'))->toBe('09:30')
        ->and($end->format('H:i'))->toBe('09:45');
});

it('ends the last quarter at the next midnight', function () {
    $day = new DateTimeImmutable('2025-01-15', new DateTimeZone('Europe/Madrid'));
    [, $end] = QuarterHourLabel::parse('24:00')->interval($day);

    expect($end->format('Y-m-d H:i'))->toBe('2025-01-16 00:00');
});
