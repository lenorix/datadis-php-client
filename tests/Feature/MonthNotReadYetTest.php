<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;

/** @param  list<array<string, mixed>>  $rows */
function consumptionOfRows(array $rows): ApiResult
{
    $s = Scenario::make();
    $s->http->queue(Responses::datadis(Payloads::envelope('timeCurve', $rows)));

    return $s->client->getConsumptionData(Scenario::cups(), '2', 5, Month::of(2025, 10), Month::of(2025, 10));
}

/** @return array<string, mixed> */
function hourRow(string $date, string $time, mixed $consumption, array $more = []): array
{
    return ['cups' => Scenario::CUPS, 'date' => $date, 'time' => $time, 'consumptionKWh' => $consumption, 'obtainMethod' => 'Real'] + $more;
}

it('answers an empty result for a month of rows without consumption, counting them', function () {
    $result = consumptionOfRows([hourRow('2025/10/01', '01:00', null), hourRow('2025/10/01', '02:00', null, ['surplusEnergyKWh' => null])]);

    expect($result->records)->toBe([])->and($result->skippedRows)->toBe(2);
});

it('leaves out a row without consumption even beside self-consumption energy, as the spec has consumption always', function () {
    $result = consumptionOfRows([hourRow('2025/10/01', '01:00', null, ['surplusEnergyKWh' => 1.2]), hourRow('2025/10/01', '02:00', 0.4)]);

    expect($result->records)->toHaveCount(1)
        ->and($result->records[0]->consumptionKWh)->toBe('0.400')
        ->and($result->skippedRows)->toBe(1);
});

it('still tells an answer of unreadable consumption from one with none', function (array $row) {
    expect(fn () => consumptionOfRows([$row]))->toThrow(UninterpretableResponseException::class, 'none of the 1 rows could be used');
})->with([
    'unreadable consumption' => [hourRow('2025/10/01', '01:00', 'broken')],
    'unreadable consumption beside a surplus' => [hourRow('2025/10/01', '01:00', 'broken', ['surplusEnergyKWh' => 1.2])],
    'consumption of another type' => [hourRow('2025/10/01', '01:00', ['1'])],
]);

it('places the second 03:00 of the autumn change day on the repeated hour when the first has no consumption', function () {
    $result = consumptionOfRows([hourRow('2025/10/26', '03:00', null), hourRow('2025/10/26', '03:00', 0.5)]);
    $zone = new DateTimeZone('Europe/Madrid');

    expect($result->records)->toHaveCount(1)
        ->and($result->records[0]->start?->getTimestamp())->toBe((new DateTimeImmutable('2025-10-26 02:00:00+01:00', $zone))->getTimestamp())
        ->and($result->records[0]->index)->toBe(2)
        ->and($result->skippedRows)->toBe(1);
});
