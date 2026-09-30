<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Time\DatadisDate;

it('parses slash dates as midnight in the given zone', function () {
    $zone = new DateTimeZone('Europe/Madrid');
    $date = DatadisDate::tryParse('2024/02/29', $zone);

    expect($date?->format('Y-m-d H:i:s e'))->toBe('2024-02-29 00:00:00 Europe/Madrid');
});

it('parses the dashed dates of ownership periods and surrounding spaces too', function (string $value) {
    expect(DatadisDate::tryParse($value, new DateTimeZone('UTC'))?->format('Y-m-d'))->toBe('2022-01-01');
})->with(['2022-01-01', ' 2022/01/01 ']);

it('returns null for empty, malformed or impossible dates', function (string $value) {
    expect(DatadisDate::tryParse($value, new DateTimeZone('UTC')))->toBeNull();
})->with(['', '2025/02/29', '2025/13/01', '2025/1/1', '2025/01-01', 'garbage', '0000/00/00']);
