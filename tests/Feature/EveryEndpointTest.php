<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;

/*
 * Every endpoint of the private API in every version that has it: the exact path and query sent,
 * and a realistic answer decoded. v1 answers are bare lists (verified); v2 answers are envelopes.
 */

$cups = 'ES0000000000000000AA0A';
$third = '00000000T';

$envelope = fn (string $key, mixed $rows) => [$key => $rows, 'distributorError' => []];

$cases = [
    'supplies' => [
        'call' => fn (DatadisClient $c) => $c->getSupplies(Nif::fromString($third), '2'),
        'path' => 'get-supplies',
        'query' => ['authorizedNif' => $third, 'distributorCode' => '2'],
        'v1' => datadisFixture('v1/supplies-authorized.json'),
        'v2key' => 'supplies',
        'check' => fn ($result) => expect($result->records[0]->cups)->toBe($cups)->and($result->records[0]->pointType)->toBe(5),
    ],
    'distributors' => [
        'call' => fn (DatadisClient $c) => $c->getDistributorsWithSupplies(Nif::fromString($third)),
        'path' => 'get-distributors-with-supplies',
        'query' => ['authorizedNif' => $third],
        'v1' => '{"distributorCodes":["7","2","5","3","8","1","6","4"]}',
        'v2' => '{"distExistenceUser":{"distributorCodes":["7","2","5","3","8","1","6","4"]},"distributorError":[]}',
        'check' => fn ($result) => expect($result->records)->toBe(['7', '2', '5', '3', '8', '1', '6', '4']),
    ],
    'contract detail' => [
        'call' => fn (DatadisClient $c) => $c->getContractDetail(Cups::fromString($cups), '2', Nif::fromString($third)),
        'path' => 'get-contract-detail',
        'query' => ['cups' => $cups, 'distributorCode' => '2', 'authorizedNif' => $third],
        'v1' => datadisFixture('v1/contract-detail-authorized.json'),
        'v2key' => 'contract',
        'check' => fn ($result) => expect($result->records[0]->codeFare)->toBe('2T')->and($result->records[0]->contractedPowerkW)->toBe(['3.45', '3.45']),
    ],
    'hourly consumption' => [
        'call' => fn (DatadisClient $c) => $c->getConsumptionData(Cups::fromString($cups), '2', 5, Month::of(2026, 7), Month::of(2026, 7), authorizedNif: Nif::fromString($third)),
        'path' => 'get-consumption-data',
        'query' => ['cups' => $cups, 'distributorCode' => '2', 'startDate' => '2026/07', 'endDate' => '2026/07', 'measurementType' => '0', 'pointType' => '5', 'authorizedNif' => $third],
        'v1' => (string) json_encode(Payloads::realMonth(2026, 7)),
        'v2key' => 'timeCurve',
        'check' => fn ($result) => expect($result->records)->toHaveCount(744)->and($result->records[743]->end?->format('Y-m-d H:i'))->toBe('2026-08-01 00:00'),
    ],
    'quarter-hourly consumption' => [
        'call' => fn (DatadisClient $c) => $c->getConsumptionData(Cups::fromString($cups), '2', 5, Month::of(2026, 6), Month::of(2026, 6), MeasurementType::QuarterHourly),
        'path' => 'get-consumption-data',
        'query' => ['cups' => $cups, 'distributorCode' => '2', 'startDate' => '2026/06', 'endDate' => '2026/06', 'measurementType' => '1', 'pointType' => '5'],
        // A type 5 supply answers quarter-hourly requests with an empty list (verified).
        'v1' => '[]',
        'v2key' => 'timeCurve',
        'check' => fn ($result) => expect($result->isEmpty())->toBeTrue(),
    ],
    'maximum power' => [
        'call' => fn (DatadisClient $c) => $c->getMaxPower(Cups::fromString($cups), '2', Month::of(2026, 7), Month::of(2026, 7), Nif::fromString($third)),
        'path' => 'get-max-power',
        'query' => ['cups' => $cups, 'distributorCode' => '2', 'startDate' => '2026/07', 'endDate' => '2026/07', 'authorizedNif' => $third],
        'v1' => datadisFixture('v1/max-power-authorized.json'),
        'v2key' => 'maxPower',
        'check' => fn ($result) => expect(array_map(fn ($r) => $r->maxPower, $result->records))->toBe(['3.516', '2.500', '2.976']),
    ],
];

foreach ($cases as $name => $case) {
    foreach ([ApiVersion::V1, ApiVersion::V2] as $version) {
        it("calls {$name} on the {$version->value} path with the exact query", function () use ($case, $version, $envelope) {
            $body = $case['v1'];
            if ($version === ApiVersion::V2) {
                $body = $case['v2'] ?? json_encode($envelope($case['v2key'], json_decode($case['v1'], true)));
            }

            $s = Scenario::make($version);
            $s->http->queue(Responses::datadis($body));

            $result = $case['call']($s->client);

            $request = $s->http->requests()[1];
            parse_str($request->getUri()->getQuery(), $query);

            expect($request->getMethod())->toBe('GET')
                ->and($request->getUri()->getPath())->toBe('/api-private/api/'.$case['path'].$version->suffix())
                ->and($query)->toBe($case['query'])
                ->and($s->http->requests())->toHaveCount(2);

            $case['check']($result);
        });
    }
}

it('calls reactive energy and groups on v2 only', function () {
    $s = Scenario::make(ApiVersion::V2);
    $s->http->queue(Responses::datadis(datadisFixture('v2/reactive.json')), Responses::datadis(datadisFixture('v2/groups.json')));

    $reactive = $s->client->getReactiveData(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 7), Month::of(2026, 7));
    $groups = $s->client->getGroups();

    parse_str($s->http->requests()[1]->getUri()->getQuery(), $query);

    expect($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/get-reactive-data-v2')
        ->and($query)->toBe(['cups' => Scenario::CUPS, 'distributorCode' => '2', 'startDate' => '2026/07', 'endDate' => '2026/07'])
        ->and($reactive->records[0]->energy[0]->periods[1])->toBe('1.500')
        ->and($s->http->requests()[2]->getUri()->getPath())->toBe('/api-private/api/get-groups-v2')
        ->and($groups->records[0]->name)->toBe('Oficinas');

    $v1 = Scenario::make(ApiVersion::V1);
    expect(fn () => $v1->client->getReactiveData(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 7), Month::of(2026, 7)))->toThrow(UnsupportedOperationException::class)
        ->and(fn () => $v1->client->getGroups())->toThrow(UnsupportedOperationException::class)
        ->and($v1->http->requests())->toBe([]);
});

it('calls the authorization and partner endpoints on the same paths whatever the version', function (ApiVersion $version) {
    $s = Scenario::make($version);
    $s->http->queue(
        Responses::empty(200),
        Responses::empty(200),
        Responses::datadis(datadisFixture('v1/list-authorization.json')),
        Responses::datadis('[]'),
        Responses::text('OK'),
        Responses::text('2026/01/01'),
    );

    $s->client->newAuthorization(Nif::fromString('00000000T'));
    $s->client->cancelAuthorization(Nif::fromString('00000000T'));
    $s->client->listAuthorization();
    $s->client->partnerUserList();
    $s->client->partnerDeleteUser(Nif::fromString('00000000T'));
    $s->client->partnerAgreementDate();

    expect(array_map(fn ($r) => $r->getUri()->getPath(), array_slice($s->http->requests(), 1)))->toBe([
        '/api-private/api/new-authorization',
        '/api-private/api/cancel-authorization',
        '/api-private/api/list-authorization',
        '/api-private/api/partner-user-list',
        '/api-private/api/partner-delete-user',
        '/api-private/api/partner-agreement-date',
    ]);
})->with([ApiVersion::V1, ApiVersion::V2]);
