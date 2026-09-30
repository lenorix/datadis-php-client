<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Time\TimeInstant;

$madrid = new DateTimeZone('Europe/Madrid');

it('parses a date and a quarter-hour time as an instant', function () use ($madrid) {
    $instant = TimeInstant::tryParse('2022/01/11', '09:45', $madrid);

    expect($instant?->format('Y-m-d H:i e'))->toBe('2022-01-11 09:45 Europe/Madrid');
});

it('understands 24:00 as the next day midnight', function () use ($madrid) {
    expect(TimeInstant::tryParse('2025/12/31', '24:00', $madrid)?->format('Y-m-d H:i'))->toBe('2026-01-01 00:00');
});

it('accepts 00:00 as midnight of the same day', function () use ($madrid) {
    expect(TimeInstant::tryParse('2025/01/01', '00:00', $madrid)?->format('Y-m-d H:i'))->toBe('2025-01-01 00:00');
});

it('returns null for malformed times or dates', function (string $date, string $time) use ($madrid) {
    expect(TimeInstant::tryParse($date, $time, $madrid))->toBeNull();
})->with([
    ['2025/01/01', '24:30'],
    ['2025/01/01', '9:45'],
    ['2025/01/01', '12:60'],
    ['2025/01/01', '25:00'],
    ['2025/01/01', ''],
    ['2025/02/30', '10:00'],
    ['', '10:00'],
]);
