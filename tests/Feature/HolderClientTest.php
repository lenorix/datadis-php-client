<?php

declare(strict_types=1);

use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;

/*
 * The professional use of Datadis is reading the supplies of the people who authorized the account.
 * A client for one holder sends their NIF on every supply and data call, so no call can forget it.
 */

it('sends the holder on every supply and data call', function () {
    $s = Scenario::make();
    $s->http->queue(
        Responses::datadis(datadisFixture('v1/supplies-authorized.json')),
        Responses::datadis('{"distExistenceUser":{"distributorCodes":["2"]},"distributorError":[]}'),
        Responses::datadis('{"contract":[],"distributorError":[]}'),
        Responses::datadis('{"timeCurve":[],"distributorError":[]}'),
        Responses::datadis('{"maxPower":[],"distributorError":[]}'),
    );
    $holder = $s->client->forHolder(Nif::fromString('87654321x'));

    $supply = $holder->findSupply(Cups::fromString(Scenario::CUPS));
    $holder->getDistributorsWithSupplies();
    $holder->getContractDetailOf($supply);
    $holder->getConsumptionDataOf($supply, Month::of(2026, 7));
    $holder->getMaxPowerOf($supply, Month::of(2026, 7));

    foreach (range(1, 5) as $request) {
        expect($s->query($request)['authorizedNif'] ?? null)->toBe('87654321X');
    }
});

it('leaves the account client reading its own supplies and shares its login', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"supplies":[],"distributorError":[]}'), Responses::datadis('{"supplies":[],"distributorError":[]}'));

    $s->client->forHolder(Nif::fromString('87654321X'))->getSupplies();
    $s->client->getSupplies();

    expect($s->http->requests())->toHaveCount(3)
        ->and($s->query(1)['authorizedNif'] ?? null)->toBe('87654321X')
        ->and($s->query(2))->not->toHaveKey('authorizedNif');
});

it('refuses another NIF on a holder client before sending anything', function () {
    $s = Scenario::make();
    $holder = $s->client->forHolder(Nif::fromString('87654321X'));

    try {
        $holder->getSupplies(Nif::fromString('X1234567L'));
    } catch (InvalidRequestException $e) {
        expect($e->requestSent)->toBeFalse()->and($s->http->requests())->toBe([]);

        return;
    }

    throw new LogicException('Expected an InvalidRequestException.');
});

it('accepts the same holder given again, however it is written', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"supplies":[],"distributorError":[]}'));

    $s->client->forHolder(Nif::fromString('87654321X'))->getSupplies(Nif::fromString(' 87654321x '));

    expect($s->query()['authorizedNif'])->toBe('87654321X');
});

it('is the account itself when the holder is the account', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"supplies":[],"distributorError":[]}'));

    $s->client->forHolder(Nif::fromString('12345678Z'))->getSupplies();

    expect($s->query())->not->toHaveKey('authorizedNif');
});

it('keeps the 24 hour guard shared with the account client', function () {
    [$client, $http, $clock] = flowClient(ledger: true);
    $http->queue(
        Responses::text(Tokens::datadis($clock->now()->getTimestamp())),
        Responses::datadis('{"maxPower":[],"distributorError":[]}'),
    );
    $maxPower = fn (DatadisClient $c) => $c->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 7));

    $maxPower($client->forHolder(Nif::fromString('87654321X')));

    // Datadis keys maximum power without authorizedNif, so the account's identical query is the same one.
    expect(fn () => $maxPower($client))->toThrow(RepetitionWindowException::class);
});
