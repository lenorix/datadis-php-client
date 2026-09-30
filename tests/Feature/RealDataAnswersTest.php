<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\Exceptions\AuthorizationException;
use Lenorix\DatadisClient\Exceptions\RequestRejectedException;
use Lenorix\DatadisClient\Tariff\AccessTariff;
use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;

/*
 * Answers captured from the live API for a supply read with authorizedNif (September 2026, v1
 * paths), with identifiers replaced and consumption values invented. These describe behaviour
 * the client already had; they pin it to what Datadis really sends.
 */

function realScenario(): Scenario
{
    return Scenario::make(ApiVersion::V1);
}

it('reads a real supply row of an authorized third party', function () {
    $s = realScenario();
    $s->http->queue(Responses::text(datadisFixture('v1/supplies-authorized.json'), 200, ['Content-Type' => 'text/plain']));

    $supply = $s->client->findSupply(Cups::fromString('ES0031300000000001JN'), Nif::fromString('87654321X'));

    expect($supply?->cups)->toBe('ES0031300000000001JN0F')
        ->and($supply?->distributorCode)->toBe('2')
        ->and($supply?->pointType)->toBe(5)
        ->and($supply?->provinceCode)->toBe('28')
        ->and($supply?->isOpenEnded())->toBeTrue()
        ->and($s->query()['authorizedNif'])->toBe('87654321X');
});

it('reads a real contract seen by a third party', function () {
    $s = realScenario();
    $s->http->queue(Responses::text(datadisFixture('v1/contract-detail-authorized.json'), 200, ['Content-Type' => 'text/plain']));

    $contract = $s->client->contractDetail(Cups::fromString(Scenario::CUPS), '2', Nif::fromString('87654321X'))->records[0];

    expect($contract->tariff())->toBe(AccessTariff::T20TD)
        ->and($contract->contractedPowerKw)->toBe(['3.45', '3.45'])
        ->and($contract->marketer)->toBe('-')
        ->and($contract->timeDiscrimination)->toBe('TARIFA DE TRES PERIODOS')
        ->and($contract->lastMarketerDate)->toBeNull()
        ->and($contract->maxPowerInstall)->toBe('5.500')
        ->and($contract->installedCapacity)->toBeNull()
        ->and($contract->ownerPeriods[0]['start']?->format('Y-m-d'))->toBe('2024-02-01')
        ->and($contract->isOpenEnded())->toBeTrue();
});

it('reads a real month of hourly consumption, every hour in place', function (int $year, int $month, int $from, int $to, int $rows) {
    $s = realScenario();
    $all = [];
    for ($m = $from; $m <= $to; $m++) {
        $all = [...$all, ...Payloads::realMonth($year, $m)];
    }
    $s->http->queue(Responses::text((string) json_encode($all, JSON_PRESERVE_ZERO_FRACTION), 200, ['Content-Type' => 'text/plain']));

    $readings = $s->client->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of($year, $from), Month::of($year, $to))->records;

    expect($readings)->toHaveCount($rows)
        ->and($readings[0]->surplusKWh)->toBeNull()
        ->and($readings[0]->isReal())->toBeTrue();

    foreach ($readings as $i => $reading) {
        expect($reading->hasValidTime())->toBeTrue();
        if ($i > 0) {
            expect($reading->start->getTimestamp())->toBe($readings[$i - 1]->end->getTimestamp());
        }
    }
})->with([
    'July: 31 days of 24 hours' => [2026, 7, 7, 7, 744],
    'March and April: the 23 hour day included' => [2026, 3, 3, 4, 1463],
]);

it('reads the current month, which has data up to about two days ago', function () {
    $s = realScenario();
    // "Now" is 2026-09-15: the answer ends on the 13th.
    $s->http->queue(Responses::datadis(Payloads::realMonth(2026, 9, untilDay: 13)));

    $readings = $s->client->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 9), Month::of(2026, 9))->records;

    expect($readings)->toHaveCount(312)
        ->and(end($readings)->end?->format('Y-m-d H:i'))->toBe('2026-09-14 00:00');
});

it('reads real maximum power rows, one per period, in kW', function () {
    $s = realScenario();
    $s->http->queue(Responses::text(datadisFixture('v1/max-power-authorized.json'), 200, ['Content-Type' => 'text/plain']));

    $readings = $s->client->maxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 7), Month::of(2026, 7))->records;

    expect(array_map(fn ($r) => $r->periodNumber(), $readings))->toBe([1, 2, 3])
        ->and($readings[0]->maxPowerKw)->toBe('3.516')
        ->and($readings[0]->instant?->format('Y-m-d H:i'))->toBe('2026-07-16 11:15')
        ->and($readings[1]->instant?->format('Y-m-d H:i'))->toBe('2026-07-10 00:00');
});

it('gets an empty answer, not an error, for a wrong but existing distributor code', function () {
    $s = realScenario();
    $s->http->queue(Responses::text('[]', 200, ['Content-Type' => 'text/plain']));

    expect($s->client->consumption(Cups::fromString(Scenario::CUPS), '1', 5, Month::of(2026, 6), Month::of(2026, 6))->isEmpty())->toBeTrue();
});

it('classifies the rest of the real refusals', function (string $body, string $class) {
    $s = realScenario();
    $s->http->queue(Responses::text($body, 400, ['Content-Type' => 'application/json;charset=UTF-8']));

    expect(fn () => $s->client->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 6), Month::of(2026, 6)))->toThrow($class);
})->with([
    'unknown distributor code on consumption' => ['CUPS o distributor no válido ', RequestRejectedException::class],
    'unknown measurement type' => ['MeasurementType incorrecto ', RequestRejectedException::class],
    'CUPS not written exactly as Datadis has it, or not authorized' => ['No se encuentra autorizado el cups introducido', AuthorizationException::class],
]);
