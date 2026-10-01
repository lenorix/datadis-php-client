<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\PartnerUser;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;

/*
 * Answers captured from the live API on the v2 paths and the partner and authorization calls
 * (October 2026), with the real status and Content-Type, anonymised (see tests/Fixtures/README.md).
 */

$holder = fn () => Nif::fromString('00000000T');

it('reads the real v2 supplies, contract, consumption and maximum power answers', function () use ($holder) {
    $s = Scenario::make();
    $s->http->queue(
        Responses::datadis(datadisFixture('v2/supplies-authorized.json')),
        Responses::datadis(datadisFixture('v2/contract-detail-authorized.json')),
        Responses::datadis(datadisFixture('v2/consumption-authorized.json')),
        Responses::datadis(datadisFixture('v2/max-power-authorized.json')),
    );
    $client = $s->client->forHolder($holder());

    $supply = $client->findSupply(Cups::fromString(Scenario::CUPS));
    $contract = $client->getContractDetailOf($supply)->records[0];
    $readings = $client->getConsumptionDataOf($supply, Month::of(2025, 11))->records;
    $peaks = $client->getMaxPowerOf($supply, Month::of(2025, 12))->records;

    expect($supply->isQueryable())->toBeTrue()
        ->and($supply->municipioCode)->toBe('000')
        ->and($contract->contractedPowerkW)->toBe(['3.45', '3.45'])
        ->and($contract->dateOwner[0]['endDate'])->toBeNull()
        ->and($contract->maxPowerInstall)->toBe('5.500')
        ->and(array_map(fn ($r) => $r->consumptionKWh, $readings))->toBe(['0.312', '0.090', '0.270'])
        ->and($readings[0]->start?->format('Y-m-d H:i'))->toBe('2025-11-01 00:00')
        ->and(array_map(fn ($p) => $p->maxPower, $peaks))->toBe(['2.750', '2.125', '3.125'])
        ->and($peaks[0]->instant?->format('Y-m-d H:i'))->toBe('2025-12-05 13:45');
});

it('reads the distributors answer of an account whose distributors failed as a failure, not as no distributors', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::json(datadisFixture('v2/distributors-failed.json')));

    $result = $s->client->getDistributorsWithSupplies();

    expect($result->isEmptyBecauseOfErrors())->toBeTrue()
        ->and(array_map(fn ($e) => $e->errorCode, $result->distributorErrors))->toBe(['15', '15']);
});

it('reads "No groups", sent as text labelled JSON, as no groups', function (string $body) {
    $s = Scenario::make();
    $s->http->queue(Responses::text($body, 200, ['Content-Type' => 'application/json']));

    expect($s->client->getGroups()->isEmpty())->toBeTrue();
})->with(['as captured' => 'No groups', 'with a line break' => "No groups\n"]);

it('still fails on any other text where groups were expected', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::text('<html>Mantenimiento</html>', 200, ['Content-Type' => 'text/html']));

    expect(fn () => $s->client->getGroups())->toThrow(UninterpretableResponseException::class);
});

it('reads the reactive answer of a period without data as no data, not as a distributor failure', function () use ($holder) {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis(datadisFixture('v2/reactive-no-data.json')));

    $result = $s->client->getReactiveData(Cups::fromString(Scenario::CUPS), '2', Month::of(2025, 11), authorizedNif: $holder());

    expect($result->records)->toBe([])
        ->and($result->skippedRows)->toBe(1)
        ->and($result->distributorErrors[0]->isNoData())->toBeTrue()
        ->and($result->isEmptyBecauseOfErrors())->toBeFalse();
});

it('reads the code description of a reactive answer under the key Datadis sends', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"reactiveEnergy":{"cups":"ES0000000000000000AA0A","energy":[{"date":"2025/11","energy_p1":0.5}],"code":"0","codeDescription":"OK"},"distributorError":[]}'));

    $reactive = $s->client->getReactiveData(Cups::fromString(Scenario::CUPS), '2', Month::of(2025, 11))->records[0];

    expect($reactive->codeDescription)->toBe('OK')->and($reactive->energy[0]->periods)->toBe([1 => '0.500']);
});

it('reads an empty quarter-hourly answer for a point type without quarter-hourly data', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"timeCurve":[],"distributorError":[]}'));

    $result = $s->client->getConsumptionData(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2025, 10), measurementType: MeasurementType::QuarterHourly);

    expect($result->isEmpty())->toBeTrue()->and($result->isEmptyBecauseOfErrors())->toBeFalse();
});

it('reads the real refusal of a repeated query, which carries no Retry-After', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadisError('Consulta ya realizada en las últimas 24 horas. ', 429));

    expect(fn () => $s->client->getConsumptionData(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2025, 11)))
        ->toThrow(RepetitionWindowException::class)
        ->and($s->http->requests())->toHaveCount(2);
});

it('reads the real authorization list, with its supply and the time of each validity date', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::json(datadisFixture('v1/list-authorization.json')));

    [$cancelled, $active] = $s->client->listAuthorization()->records;

    expect($cancelled->status)->toBe('CANCELADA')
        ->and($cancelled->cups)->toBe('ES0000000000000000AA')
        ->and($active->cups)->toBe('ES0000000000000000AA0A')
        ->and($active->validityDateStart?->format('Y-m-d H:i:s e'))->toBe('2026-02-01 00:00:00 Europe/Madrid')
        ->and($active->validityDateEnd?->format('Y-m-d H:i:s'))->toBe('2027-02-01 23:59:59')
        ->and($active->distributorCodeFather)->toBe('0022');
});

it('reads the real partner user list', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::json(datadisFixture('v1/partner-user-list.json')));

    $users = $s->client->partnerUserList()->records;

    expect($users)->toHaveCount(2)->each->toBeInstanceOf(PartnerUser::class)
        ->and($users[0]->name)->toBe('EMPRESA A')
        ->and($users[0]->document)->toBe('A00000000')
        ->and($users[0]->registrationDate?->format('Y-m-d H:i:s'))->toBe('2026-01-01 01:00:00')
        ->and($users[0]->registerApp)->toBeFalse()
        ->and($users[1]->registerApp)->toBeTrue();
});

it('reads the partner agreement date from its JSON answer', function (string $body, ?string $expected) {
    $s = Scenario::make();
    $s->http->queue(Responses::json($body));

    expect($s->client->partnerAgreementDate())->toBe($expected);
})->with([
    'none yet (verified)' => ['{"partnerAgreementDate": null}', null],
    'a date (its format is not verified)' => ['{"partnerAgreementDate": "2026/01/01"}', '2026/01/01'],
]);

it('counts the blank objects of a reactive list and reads the objects after them', function () {
    $s = Scenario::make();
    $blank = '{"cups":null,"energy":[],"code":null,"codeDescription":null}';
    $full = '{"cups":"ES0000000000000000AA0A","energy":[{"date":"2025/11","energy_p1":0.5}],"code":"0","codeDescription":"OK"}';
    $s->http->queue(Responses::datadis('{"reactiveEnergy":['.$blank.','.$blank.','.$full.',7],"distributorError":[]}'));

    $result = $s->client->getReactiveData(Cups::fromString(Scenario::CUPS), '2', Month::of(2025, 11));

    expect($result->records)->toHaveCount(1)->and($result->skippedRows)->toBe(3);
});
