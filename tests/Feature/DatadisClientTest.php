<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\Data\ConsumptionReading;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
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

const CUPS22 = 'ES0031300000000001JN0F';

it('lists supplies with the v2 endpoint', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json(datadisFixture('v2/supplies.json')));

    $result = $client->supplies();

    expect($http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/get-supplies-v2')
        ->and(queryOf($http))->toBe([])
        ->and($result->records)->toHaveCount(2)
        ->and($result->records[0]->distributorCode)->toBe('2')
        ->and($result->distributorErrors)->toBe([]);
});

it('lists supplies with the v1 endpoint and a bare list', function () {
    [$client, $http] = scenario(ApiVersion::V1);
    $http->queue(Responses::json(datadisFixture('v1/supplies.json')));

    $result = $client->supplies();

    expect($http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/get-supplies')
        ->and($result->records)->toHaveCount(1)
        ->and($result->records[0]->provinceCode)->toBe('28');
});

it('sends authorizedNif only when it is a third party', function (?string $nif, ?string $expected) {
    [$client, $http] = scenario();
    $http->queue(Responses::json('{"supplies":[],"distributorError":[]}'));

    $client->supplies($nif === null ? null : Nif::fromString($nif));

    expect(queryOf($http)['authorizedNif'] ?? null)->toBe($expected);
})->with([
    'none' => [null, null],
    'own account' => ['12345678Z', null],
    'own account lowercase and spaces' => [' 12345678z ', null],
    'third party' => ['87654321X', '87654321X'],
    'third party lowercase' => ['87654321x', '87654321X'],
]);

it('can filter supplies by distributor code', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json('{"supplies":[],"distributorError":[]}'));

    $client->supplies(distributorCode: '2');

    expect(queryOf($http))->toBe(['distributorCode' => '2']);
});

it('lists the distributors that have supplies in both shapes', function (ApiVersion $version, string $file, string $path) {
    [$client, $http] = scenario($version);
    $http->queue(Responses::json(datadisFixture($file)));

    $result = $client->distributors();

    expect($http->requests()[1]->getUri()->getPath())->toBe($path)
        ->and($result->records)->toBe($version === ApiVersion::V2 ? ['2', '5', '8'] : ['7', '2', '5']);
})->with([
    'v2' => [ApiVersion::V2, 'v2/distributors.json', '/api-private/api/get-distributors-with-supplies-v2'],
    'v1' => [ApiVersion::V1, 'v1/distributors.json', '/api-private/api/get-distributors-with-supplies'],
]);

it('reads the distributors of a v1 list wrapper', function () {
    [$client, $http] = scenario(ApiVersion::V1);
    $http->queue(Responses::json('[{"distributorCodes":["1","2"]}]'));

    expect($client->distributors()->records)->toBe(['1', '2']);
});

it('gets the contract detail', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json(datadisFixture('v2/contract-detail.json')));

    $result = $client->contractDetail(Cups::fromString(CUPS22), '2');

    expect($http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/get-contract-detail-v2')
        ->and(queryOf($http))->toBe(['cups' => CUPS22, 'distributorCode' => '2'])
        ->and($result->records[0]->codeFare)->toBe('2T');
});

it('returns an empty result for an empty contract list instead of failing', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json('{"contract":[],"distributorError":[]}'));

    expect($client->contractDetail(Cups::fromString(CUPS22), '2')->isEmpty())->toBeTrue();
});

it('requests consumption with every parameter Datadis requires', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json(datadisFixture('v2/consumption.json')));

    $result = $client->consumption(Cups::fromString(CUPS22), '2', 5, Month::of(2026, 1), Month::of(2026, 2));

    expect($http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/get-consumption-data-v2')
        ->and(queryOf($http))->toBe([
            'cups' => CUPS22,
            'distributorCode' => '2',
            'startDate' => '2026/01',
            'endDate' => '2026/02',
            'measurementType' => '0',
            'pointType' => '5',
        ])
        ->and($result->records)->toHaveCount(3)
        ->and($result->skippedRows)->toBe(2)
        ->and($result->records[0])->toBeInstanceOf(ConsumptionReading::class);
});

it('requests quarter-hourly data and reads quarter labels', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json(Payloads::envelope('timeCurve', [
        ['cups' => CUPS22, 'date' => '2026/01/01', 'time' => '00:15', 'consumptionKWh' => 0.05, 'obtainMethod' => 'Real'],
    ])));

    $result = $client->consumption(Cups::fromString(CUPS22), '2', 1, Month::of(2026, 1), Month::of(2026, 1), MeasurementType::QuarterHourly);

    expect(queryOf($http)['measurementType'])->toBe('1')->and($result->records[0]->index)->toBe(0);
});

it('keeps the 25 hour and the 23 hour day intact', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json(Payloads::envelope('timeCurve', [
        ...Payloads::hourlyRows('2025/10/26', Payloads::autumnDay()),
        ...Payloads::hourlyRows('2026/03/29', Payloads::springDay()),
    ])));

    $result = $client->consumption(Cups::fromString(CUPS22), '2', 5, Month::of(2025, 10), Month::of(2026, 3));
    $autumn = array_filter($result->records, fn ($r) => $r->date === '2025/10/26');
    $spring = array_filter($result->records, fn ($r) => $r->date === '2026/03/29');

    expect($autumn)->toHaveCount(25)->and($spring)->toHaveCount(23)
        ->and(array_column(array_map(fn ($r) => ['t' => $r->time], array_values($autumn)), 't')[2])->toBe('03:00');
});

it('places consumption days in the configured time zone', function () {
    [$client, $http] = scenario(zone: new DateTimeZone('Atlantic/Canary'));
    $http->queue(Responses::json(datadisFixture('v2/consumption.json')));

    $result = $client->consumption(Cups::fromString(CUPS22), '2', 5, Month::of(2026, 1), Month::of(2026, 1));

    expect($result->records[0]->day->getTimezone()->getName())->toBe('Atlantic/Canary');
});

it('reports a distributor failure hidden inside a 200 as data, not as an exception', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json(datadisFixture('v2/distributor-error-only.json')));

    $result = $client->consumption(Cups::fromString(CUPS22), '2', 5, Month::of(2026, 1), Month::of(2026, 1));

    expect($result->isEmptyBecauseOfErrors())->toBeTrue()->and($result->distributorErrors[0]->errorCode)->toBe('50');
});

it('requests the maximum power without measurement type or point type', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json(datadisFixture('v2/max-power.json')));

    $result = $client->maxPower(Cups::fromString(CUPS22), '2', Month::of(2026, 1), Month::of(2026, 1));

    expect($http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/get-max-power-v2')
        ->and(queryOf($http))->toBe(['cups' => CUPS22, 'distributorCode' => '2', 'startDate' => '2026/01', 'endDate' => '2026/01'])
        ->and($result->records)->toHaveCount(2);
});

it('requests reactive energy in v2', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json(datadisFixture('v2/reactive.json')));

    $result = $client->reactive(Cups::fromString(CUPS22), '2', Month::of(2026, 1), Month::of(2026, 1));

    expect($http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/get-reactive-data-v2')
        ->and($result->records)->toHaveCount(1)
        ->and($result->records[0]->entries)->toHaveCount(2);
});

it('returns an empty result for an empty reactive answer', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json('{"reactiveEnergy":{},"distributorError":[]}'));

    expect($client->reactive(Cups::fromString(CUPS22), '2', Month::of(2026, 1), Month::of(2026, 1))->isEmpty())->toBeTrue();
});

it('refuses reactive energy in v1 without sending anything', function () {
    [$client, $http] = scenario(ApiVersion::V1);

    try {
        $client->reactive(Cups::fromString(CUPS22), '2', Month::of(2026, 1), Month::of(2026, 1));
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
    'empty distributor code' => [fn (DatadisClient $c) => $c->contractDetail(Cups::fromString(CUPS22), '')],
    'distributor code with spaces' => [fn (DatadisClient $c) => $c->contractDetail(Cups::fromString(CUPS22), '2 3')],
    'point type 0' => [fn (DatadisClient $c) => $c->consumption(Cups::fromString(CUPS22), '2', 0, Month::of(2026, 1), Month::of(2026, 1))],
    'point type 6' => [fn (DatadisClient $c) => $c->consumption(Cups::fromString(CUPS22), '2', 6, Month::of(2026, 1), Month::of(2026, 1))],
    'reversed range' => [fn (DatadisClient $c) => $c->maxPower(Cups::fromString(CUPS22), '2', Month::of(2026, 3), Month::of(2026, 1))],
    'future month' => [fn (DatadisClient $c) => $c->maxPower(Cups::fromString(CUPS22), '2', Month::of(2026, 9), Month::of(2026, 10))],
    'boundary month two years back' => [fn (DatadisClient $c) => $c->maxPower(Cups::fromString(CUPS22), '2', Month::of(2024, 9), Month::of(2026, 1))],
    'older than two years' => [fn (DatadisClient $c) => $c->reactive(Cups::fromString(CUPS22), '2', Month::of(2023, 1), Month::of(2023, 1))],
]);

it('accepts the whole 24 month window', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json('{"maxPower":[],"distributorError":[]}'));

    $client->maxPower(Cups::fromString(CUPS22), '2', Month::of(2024, 10), Month::of(2026, 9));

    expect(queryOf($http)['startDate'])->toBe('2024/10')->and(queryOf($http)['endDate'])->toBe('2026/09');
});

it('lets the HTTP failures through as typed exceptions', function (Closure $response, string $class) {
    [$client, $http] = scenario();
    $http->queue($response());

    try {
        $client->consumption(Cups::fromString(CUPS22), '2', 5, Month::of(2026, 1), Month::of(2026, 1));
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($class);

        return;
    }

    throw new LogicException('Expected an exception.');
})->with([
    'no data' => [fn () => Responses::text('Data not found', 404), NoDataException::class],
    'repetition window' => [fn () => Responses::text('Consulta ya realizada en las últimas 24 horas', 429), RepetitionWindowException::class],
    'network failure' => [fn () => new ConnectException('cURL error 28', new Request('GET', 'https://datadis.test')), TransportException::class],
]);

it('finds the supply of a CUPS', function () {
    [$client, $http] = scenario();
    $http->queue(Responses::json(datadisFixture('v2/supplies.json')));

    $supply = $client->findSupply(Cups::fromString('ES0031300000000001JN'));

    expect($supply?->cups)->toBe('ES0031300000000001JN0F')->and($supply?->isQueryable())->toBeTrue();
});

it('builds with the default Guzzle transport', function () {
    expect(new DatadisClient(new DatadisConfig('12345678Z', 'secret')))->toBeInstanceOf(DatadisClient::class);
});
