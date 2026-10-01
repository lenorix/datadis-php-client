<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;

function scenario(ApiVersion $version = ApiVersion::V2, ?DateTimeZone $zone = null): array
{
    $s = Scenario::make($version, $zone);

    return [$s->client, $s->http, $s->clock];
}

function queryOf(FakeHttpClient $http, int $index = 1): array
{
    parse_str($http->requests()[$index]->getUri()->getQuery(), $query);

    return $query;
}

const CUPS22 = 'ES0000000000000000AA0A';

it('sends authorizedNif only when it is a third party', function (?string $nif, ?string $expected) {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis('{"supplies":[],"distributorError":[]}'));

    $client->getSupplies($nif === null ? null : Nif::fromString($nif));

    expect(queryOf($http)['authorizedNif'] ?? null)->toBe($expected);
})->with([
    'none' => [null, null],
    'own account' => ['A00000000', null],
    'own account lowercase and spaces' => [' a00000000 ', null],
    'third party' => ['00000000T', '00000000T'],
    'third party lowercase' => ['00000000t', '00000000T'],
]);

it('can filter supplies by distributor code', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis('{"supplies":[],"distributorError":[]}'));

    $client->getSupplies(distributorCode: '2');

    expect(queryOf($http))->toBe(['distributorCode' => '2']);
});

it('reads the distributors of a v1 list wrapper', function () {
    [$client, $http] = scenario(ApiVersion::V1);
    $http->queue(Responses::datadis('[{"distributorCodes":["1","2"]}]'));

    expect($client->getDistributorsWithSupplies()->records)->toBe(['1', '2']);
});

it('returns an empty result for an empty contract list instead of failing', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis('{"contract":[],"distributorError":[]}'));

    expect($client->getContractDetail(Cups::fromString(CUPS22), '2')->isEmpty())->toBeTrue();
});

it('places consumption days in the configured time zone', function () {
    [$client, $http] = scenario(zone: new DateTimeZone('Atlantic/Canary'));
    $http->queue(Responses::datadis(datadisFixture('v2/consumption.json')));

    $result = $client->getConsumptionData(Cups::fromString(CUPS22), '2', 5, Month::of(2026, 1), Month::of(2026, 1));

    expect($result->records[0]->day->getTimezone()->getName())->toBe('Atlantic/Canary');
});

it('reports a distributor failure hidden inside a 200 as data, not as an exception', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis(datadisFixture('v2/distributor-error-only.json')));

    $result = $client->getConsumptionData(Cups::fromString(CUPS22), '2', 5, Month::of(2026, 1), Month::of(2026, 1));

    expect($result->isEmptyBecauseOfErrors())->toBeTrue()->and($result->distributorErrors[0]->errorCode)->toBe('50');
});

it('returns an empty result for an empty reactive answer', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis('{"reactiveEnergy":{},"distributorError":[]}'));

    expect($client->getReactiveData(Cups::fromString(CUPS22), '2', Month::of(2026, 1), Month::of(2026, 1))->isEmpty())->toBeTrue();
});

it('refuses reactive energy in v1 without sending anything', function () {
    [$client, $http] = scenario(ApiVersion::V1);

    try {
        $client->getReactiveData(Cups::fromString(CUPS22), '2', Month::of(2026, 1), Month::of(2026, 1));
    } catch (UnsupportedOperationException $e) {
        expect($e->requestSent)->toBeFalse()->and($http->requests())->toBe([]);

        return;
    }

    throw new LogicException('Expected an UnsupportedOperationException.');
});

it('refuses invalid requests before anything is sent', function (Closure $call) {
    [$client, $http] = scenario();

    try {
        $call($client);
    } catch (InvalidRequestException $e) {
        expect($e->requestSent)->toBeFalse()->and($http->requests())->toBe([]);

        return;
    }

    throw new LogicException('Expected an InvalidRequestException.');
})->with([
    'empty distributor code' => [fn (DatadisClient $c) => $c->getContractDetail(Cups::fromString(CUPS22), '')],
    'distributor code with spaces' => [fn (DatadisClient $c) => $c->getContractDetail(Cups::fromString(CUPS22), '2 3')],
    'point type 0' => [fn (DatadisClient $c) => $c->getConsumptionData(Cups::fromString(CUPS22), '2', 0, Month::of(2026, 1), Month::of(2026, 1))],
    'point type 6' => [fn (DatadisClient $c) => $c->getConsumptionData(Cups::fromString(CUPS22), '2', 6, Month::of(2026, 1), Month::of(2026, 1))],
    'reversed range' => [fn (DatadisClient $c) => $c->getMaxPower(Cups::fromString(CUPS22), '2', Month::of(2026, 3), Month::of(2026, 1))],
    'future month' => [fn (DatadisClient $c) => $c->getMaxPower(Cups::fromString(CUPS22), '2', Month::of(2026, 9), Month::of(2026, 10))],
    'boundary month two years back' => [fn (DatadisClient $c) => $c->getMaxPower(Cups::fromString(CUPS22), '2', Month::of(2024, 9), Month::of(2026, 1))],
    'older than two years' => [fn (DatadisClient $c) => $c->getReactiveData(Cups::fromString(CUPS22), '2', Month::of(2023, 1), Month::of(2023, 1))],
]);

it('accepts the whole 24 month window', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis('{"maxPower":[],"distributorError":[]}'));

    $client->getMaxPower(Cups::fromString(CUPS22), '2', Month::of(2024, 10), Month::of(2026, 9));

    expect(queryOf($http)['startDate'])->toBe('2024/10')->and(queryOf($http)['endDate'])->toBe('2026/09');
});

it('lets the HTTP failures through as typed exceptions', function (Closure $response, string $class) {
    [$client, $http] = scenario();
    $http->queue($response());

    try {
        $client->getConsumptionData(Cups::fromString(CUPS22), '2', 5, Month::of(2026, 1), Month::of(2026, 1));
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->requestSent)->toBeTrue()
            ->and($e->endpoint)->toBe('get-consumption-data-v2')
            ->and($http->requests())->toHaveCount(2)
            ->and($http->pending())->toBe(0);

        return;
    }

    throw new LogicException('Expected an exception.');
})->with([
    'no data' => [fn () => Responses::datadisError('Data not found', 404), NoDataException::class],
    'repetition window' => [fn () => Responses::datadisError('Consulta ya realizada en las últimas 24 horas', 429), RepetitionWindowException::class],
    'network failure' => [fn () => new ConnectException('cURL error 28', new Request('GET', 'https://datadis.test')), TransportException::class],
]);

it('finds the supply of a CUPS', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis(datadisFixture('v2/supplies.json')));

    $supply = $client->findSupply(Cups::fromString('ES0000000000000000AA'));

    expect($supply?->cups)->toBe('ES0000000000000000AA0A')->and($supply?->isQueryable())->toBeTrue();
});

it('gives the readings of both change days consecutive one hour intervals', function (string $date, array $times, int $month, int $year) {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis(Payloads::envelope('timeCurve', Payloads::hourlyRows($date, $times))));

    $readings = $client->getConsumptionData(Cups::fromString(CUPS22), '2', 5, Month::of($year, $month), Month::of($year, $month))->records;
    $zone = new DateTimeZone('Europe/Madrid');
    $midnight = new DateTimeImmutable(str_replace('/', '-', $date), $zone);

    expect($readings[0]->start?->getTimestamp())->toBe($midnight->getTimestamp())
        ->and($readings[array_key_last($readings)]->end?->getTimestamp())->toBe($midnight->modify('+1 day')->getTimestamp());

    foreach ($readings as $i => $reading) {
        expect($reading->end->getTimestamp() - $reading->start->getTimestamp())->toBe(3600);
        if ($i > 0) {
            expect($reading->start->getTimestamp())->toBe($readings[$i - 1]->end->getTimestamp());
        }
    }
})->with([
    'autumn' => ['2025/10/26', Payloads::autumnDay(), 10, 2025],
    'spring' => ['2026/03/29', Payloads::springDay(), 3, 2026],
]);

it('flags a third repetition and a label in the skipped hour instead of inventing an interval', function () {
    [$client, $http] = scenario();
    $rows = [
        ...Payloads::hourlyRows('2025/10/26', ['03:00', '03:00', '03:00']),
        ...Payloads::hourlyRows('2026/03/29', ['03:00']),
    ];
    $http->queue(Responses::datadis(Payloads::envelope('timeCurve', $rows)));

    $readings = $client->getConsumptionData(Cups::fromString(CUPS22), '2', 5, Month::of(2025, 10), Month::of(2026, 3))->records;

    expect(array_map(fn ($r) => $r->hasValidTime(), $readings))->toBe([true, true, false, false]);
});

it('skips a row with an absurd number instead of failing the whole answer', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis(Payloads::envelope('timeCurve', [
        ['date' => '2026/01/01', 'time' => '01:00', 'consumptionKWh' => '1e99999999999999999999'],
        ['date' => '2026/01/01', 'time' => '02:00', 'consumptionKWh' => 0.5],
    ])));

    $result = $client->getConsumptionData(Cups::fromString(CUPS22), '2', 5, Month::of(2026, 1), Month::of(2026, 1));

    expect($result->records)->toHaveCount(1)->and($result->skippedRows)->toBe(1);
});

it('reads reactive energy tolerantly but never turns an unknown answer into an empty result', function (string $body, ?int $records) {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis($body));
    $call = fn () => $client->getReactiveData(Cups::fromString(CUPS22), '2', Month::of(2026, 1), Month::of(2026, 1));

    if ($records === null) {
        expect($call)->toThrow(UninterpretableResponseException::class);

        return;
    }

    expect($call()->records)->toHaveCount($records);
})->with([
    'message only' => ['{"message":"Internal error"}', null],
    'unknown object' => ['{"foo":1}', null],
    'empty object' => ['{}', null],
    'list of scalars' => ['{"reactiveEnergy":[1,2]}', null],
    'bare list' => ['[{"cups":"x","energy":[]}]', null],
    'reactive energy is a scalar' => ['{"reactiveEnergy":"x"}', null],
    'reactive energy as a list' => ['{"reactiveEnergy":[{"cups":"x","energy":[{"date":"2026/01","energy_p1":1}]},{"cups":"y"}]}', 2],
    'empty list of reactive energy' => ['{"reactiveEnergy":[]}', 0],
    'only distributor errors' => ['{"distributorError":[{"errorCode":"1"}]}', 0],
    'empty answer' => ['[]', 0],
    'absurd number' => ['{"reactiveEnergy":{"cups":"x","energy":[{"date":"2026/01","energy_p1":"1e99999999999999999999"}]}}', 1],
]);

it('reads distributor codes in every shape seen and refuses unknown ones', function (string $body, ?array $codes) {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis($body));

    if ($codes === null) {
        expect(fn () => $client->getDistributorsWithSupplies())->toThrow(UninterpretableResponseException::class);

        return;
    }

    expect($client->getDistributorsWithSupplies()->records)->toBe($codes);
})->with([
    'empty answer' => ['[]', []],
    'only distributor errors' => ['{"distributorError":[{"errorCode":"1"}]}', []],
    'bare list of codes' => ['["2","5"]', ['2', '5']],
    'numbers' => ['[2,5]', ['2', '5']],
    'list under distExistenceUser' => ['{"distExistenceUser":["2","5"]}', ['2', '5']],
    'object without codes' => ['{"distExistenceUser":{"other":1}}', null],
    'unknown object' => ['{"foo":1}', null],
    'codes is a scalar' => ['{"distributorCodes":"2"}', null],
]);

it('keeps a distributor error sent as a single object', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis('{"timeCurve":[],"distributorError":{"distributorCode":"2","errorCode":"50","errorDescription":"Error interno distribuidora"}}'));

    $result = $client->getConsumptionData(Cups::fromString(CUPS22), '2', 5, Month::of(2026, 1), Month::of(2026, 1));

    expect($result->isEmptyBecauseOfErrors())->toBeTrue()->and($result->distributorErrors[0]->errorCode)->toBe('50');
});

it('judges the 24 month window by the Madrid calendar even when reading Canary Islands data', function () {
    $http = new FakeHttpClient;
    // 23:30 on 30 September in the Canary Islands is already 1 October in Madrid.
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-30 23:30:00', new DateTimeZone('Atlantic/Canary')));
    $client = new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, timeZone: new DateTimeZone('Atlantic/Canary'));
    $http->queue(Responses::text(Tokens::jwt(['exp' => $clock->now()->getTimestamp() + 3600])), Responses::datadis('{"maxPower":[]}'));

    expect(fn () => $client->getMaxPower(Cups::fromString(CUPS22), '2', Month::of(2024, 10), Month::of(2024, 10)))->toThrow(InvalidRequestException::class)
        ->and($http->requests())->toBe([]);

    $client->getMaxPower(Cups::fromString(CUPS22), '2', Month::of(2026, 10), Month::of(2026, 10));

    expect($http->requests())->toHaveCount(2);
});

it('skips reactive entries that are not objects', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis('{"reactiveEnergy":[1,{"cups":"x"}]}'));

    $result = $client->getReactiveData(Cups::fromString(CUPS22), '2', Month::of(2026, 1), Month::of(2026, 1));

    expect($result->records)->toHaveCount(1)->and($result->skippedRows)->toBe(1);
});

it('counts unusable distributor codes and keeps reading after them', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis('[{"distributorCodes":["2",null,"",5]}, "8"]'));

    $result = $client->getDistributorsWithSupplies();

    expect($result->records)->toBe(['2', '5', '8'])->and($result->skippedRows)->toBe(2);
});

it('refuses an empty object where an envelope was expected', function (string $body) {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis($body));

    $client->getConsumptionData(Cups::fromString(CUPS22), '2', 5, Month::of(2026, 1), Month::of(2026, 1));
})->with(['{}', "\n{ }\n"])->throws(UninterpretableResponseException::class);

it('keeps a distributor error sent as plain text', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::datadis('{"timeCurve":[],"distributorError":"Error interno distribuidora"}'));

    $result = $client->getConsumptionData(Cups::fromString(CUPS22), '2', 5, Month::of(2026, 1), Month::of(2026, 1));

    expect($result->isEmptyBecauseOfErrors())->toBeTrue()->and($result->distributorErrors[0]->errorDescription)->toBe('Error interno distribuidora');
});
