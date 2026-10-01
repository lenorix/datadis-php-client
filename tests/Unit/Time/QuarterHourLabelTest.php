<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Time\QuarterHourConvention;
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

it('refuses with a clear error to parse a label that is not a quarter', function (string $label) {
    QuarterHourLabel::parse($label);
})->with(['00:00', '24:15', '10:05'])->throws(InvalidArgumentException::class);

it('reads the other possible convention: the hour that ends, then the minute the quarter starts', function (string $label, string $start, string $end, int $index) {
    $day = new DateTimeImmutable('2025-01-15', new DateTimeZone('Europe/Madrid'));
    $parsed = QuarterHourLabel::tryParse($label, QuarterHourConvention::HourEndingWithStartMinute);
    [$from, $to] = $parsed?->interval($day) ?? [null, null];

    expect($from?->format('Y-m-d H:i'))->toBe($start)
        ->and($to?->format('Y-m-d H:i'))->toBe($end)
        ->and($parsed?->index())->toBe($index);
})->with([
    'first quarter' => ['01:00', '2025-01-15 00:00', '2025-01-15 00:15', 0],
    'second quarter' => ['01:15', '2025-01-15 00:15', '2025-01-15 00:30', 1],
    'last quarter' => ['24:45', '2025-01-15 23:45', '2025-01-16 00:00', 95],
]);

it('rejects in the other convention the labels it cannot have', function (string $value) {
    expect(QuarterHourLabel::tryParse($value, QuarterHourConvention::HourEndingWithStartMinute))->toBeNull();
})->with(['00:00', '00:15', '00:45', '25:00', '24:50']);

it('tells the convention of an answer from the labels only one of them has', function (array $labels, ?QuarterHourConvention $expected) {
    expect(QuarterHourConvention::detect($labels))->toBe($expected);
})->with([
    'end of the quarter: hour 00 appears' => [['00:15', '00:30', '00:45', '01:00', '24:00'], QuarterHourConvention::QuarterEnd],
    'hour ending: 24:15 to 24:45 appear' => [['01:00', '01:15', '24:30', '24:45'], QuarterHourConvention::HourEndingWithStartMinute],
    'neither, as in a partial day' => [['10:00', '10:15', '10:30'], null],
    'both, which no answer should have' => [['00:15', '24:45'], null],
    'no labels' => [[], null],
    'only labels that merely contain them' => [['100:15', '00:150', "00:15\n", '124:30', '24:300', "24:45\n"], null],
]);
