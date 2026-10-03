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
        '{"reactiveEnergy":{"cups":null,"energy":[],"code":null,"codeDescription":null},"distributorError":[]}',
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

it('refuses before sending a range that starts before the contract, which Datadis refuses and counts', function (Closure $call) {
    $s = Scenario::make();
    $supply = supplyAsListed(['validDateFrom' => '2026/03/15']);

    expect(fn () => $call($s->client, $supply, Month::of(2026, 2)))->toThrow(InvalidRequestException::class, '2026/03')
        ->and($s->http->requests())->toBe([]);
})->with([
    'consumption' => [fn (DatadisClient $c, Supply $s, Month $m) => $c->getConsumptionDataOf($s, $m, Month::of(2026, 4))],
    'max power' => [fn (DatadisClient $c, Supply $s, Month $m) => $c->getMaxPowerOf($s, $m)],
    'reactive' => [fn (DatadisClient $c, Supply $s, Month $m) => $c->getReactiveDataOf($s, $m)],
]);

it('sends a range that starts in the month the contract starts', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"timeCurve":[],"distributorError":[]}'));

    $s->client->getConsumptionDataOf(supplyAsListed(['validDateFrom' => '2026/03/15']), Month::of(2026, 3));

    expect($s->query()['startDate'])->toBe('2026/03');
});

it('queries the contract, maximum power and reactive data of a supply listed without a point type, which only consumption needs', function (Closure $call, string $path, string $answer) {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis($answer));

    $call($s->client, supplyAsListed(['pointType' => null]));

    expect($s->http->requests()[1]->getUri()->getPath())->toBe("/api-private/api/{$path}-v2")
        ->and($s->query())->not->toHaveKey('pointType');
})->with([
    'contract detail' => [fn (DatadisClient $c, Supply $s) => $c->getContractDetailOf($s), 'get-contract-detail', '{"contract":[],"distributorError":[]}'],
    'max power' => [fn (DatadisClient $c, Supply $s) => $c->getMaxPowerOf($s, Month::of(2026, 7)), 'get-max-power', '{"maxPower":[],"distributorError":[]}'],
    'reactive' => [fn (DatadisClient $c, Supply $s) => $c->getReactiveDataOf($s, Month::of(2026, 7)), 'get-reactive-data', '{"reactiveEnergy":{"cups":null,"energy":[],"code":null,"codeDescription":null},"distributorError":[]}'],
]);

it('refuses the consumption of a supply listed without a point type, naming it', function () {
    $s = Scenario::make();

    expect(fn () => $s->client->getConsumptionDataOf(supplyAsListed(['pointType' => null]), Month::of(2026, 7)))->toThrow(InvalidRequestException::class, 'point type')
        ->and($s->http->requests())->toBe([]);
});

it('refuses before sending a range that ends after the contract, which Datadis is reported to refuse and count', function (Closure $call) {
    $s = Scenario::make();
    $supply = supplyAsListed(['validDateTo' => '2026/05/31']);

    expect(fn () => $call($s->client, $supply))->toThrow(InvalidRequestException::class, 'ends after the contract of the supply (2026/05)')
        ->and($s->http->requests())->toBe([]);
})->with([
    'consumption into the next month' => [fn (DatadisClient $c, Supply $s) => $c->getConsumptionDataOf($s, Month::of(2026, 5), Month::of(2026, 6))],
    'max power of the next month' => [fn (DatadisClient $c, Supply $s) => $c->getMaxPowerOf($s, Month::of(2026, 6))],
    'reactive of the next month' => [fn (DatadisClient $c, Supply $s) => $c->getReactiveDataOf($s, Month::of(2026, 6))],
]);

it('sends a range that ends in the month the contract ends', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"maxPower":[],"distributorError":[]}'));

    $s->client->getMaxPowerOf(supplyAsListed(['validDateTo' => '2026/05/31']), Month::of(2026, 4), Month::of(2026, 5));

    expect($s->query()['endDate'])->toBe('2026/05');
});

it('says which months a data query asked for, and leaves the lists without them', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"reactiveEnergy":{"cups":null,"energy":[],"code":null,"codeDescription":null},"distributorError":[]}'), Responses::datadis('{"supplies":[],"distributorError":[]}'));

    $reactive = $s->client->getReactiveDataOf(supplyAsListed(), Month::of(2026, 5), Month::of(2026, 7));

    expect($reactive->startDate?->format())->toBe('2026/05')->and($reactive->endDate?->format())->toBe('2026/07')
        ->and($s->client->getSupplies()->startDate)->toBeNull();
});
