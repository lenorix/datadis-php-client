<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\MeasurementType;

/*
 * A supply from the list is the starting point of every other call: the shortcuts send its CUPS,
 * distributor code and point type exactly as Datadis returned them.
 */

function supplyAsListed(array $overrides = []): Supply
{
    $row = $overrides + ['cups' => Scenario::CUPS, 'postalCode' => '28001', 'validDateFrom' => '2020/01/01', 'validDateTo' => '', 'pointType' => 5, 'distributorCode' => '2'];

    return Supply::fromRow($row, new DateTimeZone('Europe/Madrid')) ?? throw new LogicException('Not a supply row.');
}

it('queries a supply with the codes it was listed with, one month when no end is given', function (Closure $call, string $path, array $query, string $answer) {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis($answer));

    $call($s->client, supplyAsListed());

    expect($s->http->requests()[1]->getUri()->getPath())->toBe("/api-private/api/{$path}-v2")
        ->and($s->query())->toBe($query);
})->with([
    'consumption' => [
        fn (DatadisClient $c, Supply $s) => $c->getConsumptionDataOf($s, Month::of(2026, 7), measurementType: MeasurementType::QuarterHourly),
        'get-consumption-data',
        ['cups' => Scenario::CUPS, 'distributorCode' => '2', 'startDate' => '2026/07', 'endDate' => '2026/07', 'measurementType' => '1', 'pointType' => '5'],
        '{"timeCurve":[],"distributorError":[]}',
    ],
    'max power' => [
        fn (DatadisClient $c, Supply $s) => $c->getMaxPowerOf($s, Month::of(2026, 5), Month::of(2026, 7)),
        'get-max-power',
        ['cups' => Scenario::CUPS, 'distributorCode' => '2', 'startDate' => '2026/05', 'endDate' => '2026/07'],
        '{"maxPower":[],"distributorError":[]}',
    ],
    'reactive' => [
        fn (DatadisClient $c, Supply $s) => $c->getReactiveDataOf($s, Month::of(2026, 7)),
        'get-reactive-data',
        ['cups' => Scenario::CUPS, 'distributorCode' => '2', 'startDate' => '2026/07', 'endDate' => '2026/07'],
        '{"reactiveEnergy":{},"distributorError":[]}',
    ],
    'contract detail' => [
        fn (DatadisClient $c, Supply $s) => $c->getContractDetailOf($s),
        'get-contract-detail',
        ['cups' => Scenario::CUPS, 'distributorCode' => '2'],
        '{"contract":[],"distributorError":[]}',
    ],
]);

it('sends nothing for a supply listed without usable codes', function (array $row) {
    $s = Scenario::make();

    try {
        $s->client->getConsumptionDataOf(supplyAsListed($row), Month::of(2026, 7));
    } catch (InvalidRequestException $e) {
        expect($e->requestSent)->toBeFalse()
            ->and($s->http->requests())->toBe([]);

        return;
    }

    throw new LogicException('Expected an InvalidRequestException.');
})->with([
    'no distributor code' => [['distributorCode' => '']],
    'no point type' => [['pointType' => null]],
    'not a CUPS' => [['cups' => 'ES000']],
]);
