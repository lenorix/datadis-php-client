<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\AtomicCache;
use Lenorix\DatadisClient\Tests\Support\DatadisWithTheRule;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;

/*
 * An application that kept a record of its own tells the ledger what it sent in the last day, with
 * the same arguments it would give to the call, so the ledger refuses those very queries.
 */

/** @return array{DatadisClient, FakeHttpClient, FrozenClock} */
function rememberingClient(bool $atomic = false): array
{
    $s = Scenario::make(ledger: fn (FrozenClock $clock) => Scenario::ledger($clock, $atomic ? new AtomicCache : null));

    return [$s->client, $s->http, $s->clock];
}

it('refuses a remembered query until its own window ends, then lets it go', function (bool $atomic) {
    [$client, $http, $clock] = rememberingClient($atomic);
    $sentAt = $clock->now()->modify('-23 hours');

    expect($client->rememberConsumptionData($sentAt, Scenario::cups(), '2', 5, Month::of(2026, 8)))->toBeTrue();

    try {
        $client->getConsumptionData(Scenario::cups(), '2', 5, Month::of(2026, 8));
        throw new LogicException('Expected a RepetitionWindowException.');
    } catch (RepetitionWindowException $e) {
        expect($e->httpStatus)->toBeNull()
            ->and($e->lastAttemptAt?->getTimestamp())->toBe($sentAt->getTimestamp())
            ->and($e->availableAt?->getTimestamp())->toBe($sentAt->getTimestamp() + RequestLedger::WINDOW_SECONDS)
            ->and($http->requests())->toBe([]);
    }

    // The test's atomic store never expires a key, and a held key always counts: freeing it is the store's TTL.
    if ($atomic) {
        return;
    }

    $clock->advance($e->availableAt->getTimestamp() - $clock->now()->getTimestamp());
    $http->queue(Responses::datadis('{"timeCurve":[],"distributorError":[]}'));
    $client->getConsumptionData(Scenario::cups(), '2', 5, Month::of(2026, 8));

    expect($http->requests())->toHaveCount(2);
})->with(['a plain store' => [false], 'an atomic store' => [true]]);

it('does not record an attempt older than the window', function () {
    [$client, $http, $clock] = rememberingClient();
    $http->queue(Responses::datadis('{"maxPower":[],"distributorError":[]}'));

    expect($client->rememberMaxPower($clock->now()->modify('-'.RequestLedger::WINDOW_SECONDS.' seconds'), Scenario::cups(), '2', Month::of(2026, 8)))->toBeFalse();

    $client->getMaxPower(Scenario::cups(), '2', Month::of(2026, 8));

    expect($http->requests())->toHaveCount(2);
});

it('keeps the newest attempt of a query, whatever order the history comes in', function (bool $atomic) {
    [$client, , $clock] = rememberingClient($atomic);
    $older = $clock->now()->modify('-20 hours');
    $newer = $clock->now()->modify('-2 hours');

    expect($client->rememberMaxPower($older, Scenario::cups(), '2', Month::of(2026, 8)))->toBeTrue()
        ->and($client->rememberMaxPower($newer, Scenario::cups(), '2', Month::of(2026, 8)))->toBeTrue()
        ->and($client->rememberMaxPower($older, Scenario::cups(), '2', Month::of(2026, 8)))->toBeFalse()
        ->and($client->rememberMaxPower($newer, Scenario::cups(), '2', Month::of(2026, 8)))->toBeFalse();

    try {
        $client->getMaxPower(Scenario::cups(), '2', Month::of(2026, 8));
    } catch (RepetitionWindowException $e) {
        expect($e->lastAttemptAt?->getTimestamp())->toBe($newer->getTimestamp());

        return;
    }

    throw new LogicException('Expected a RepetitionWindowException.');
})->with(['a plain store' => [false], 'an atomic store' => [true]]);

it('builds the remembered query exactly as the call builds it', function (Closure $remember, Closure $call) {
    [$client, $http, $clock] = rememberingClient();
    $remember($client, $clock->now());

    expect(fn () => $call($client))->toThrow(RepetitionWindowException::class)
        ->and($http->requests())->toBe([]);
})->with([
    'the account\'s own NIF is left out, as when sending' => [
        fn (DatadisClient $c, DateTimeImmutable $now) => $c->rememberConsumptionData($now->modify('-1 hour'), Scenario::cups(), '2', 5, Month::of(2026, 8), authorizedNif: Nif::fromString('A00000000')),
        fn (DatadisClient $c) => $c->getConsumptionData(Scenario::cups(), '2', 5, Month::of(2026, 8)),
    ],
    'one month is a range of one month' => [
        fn (DatadisClient $c, DateTimeImmutable $now) => $c->rememberConsumptionData($now->modify('-1 hour'), Scenario::cups(), '2', 5, Month::of(2026, 8), Month::of(2026, 8)),
        fn (DatadisClient $c) => $c->getConsumptionData(Scenario::cups(), '2', 5, Month::of(2026, 8)),
    ],
    'a holder client sends its holder' => [
        fn (DatadisClient $c, DateTimeImmutable $now) => $c->forHolder(Nif::fromString('00000000T'))->rememberConsumptionData($now->modify('-1 hour'), Scenario::cups(), '2', 5, Month::of(2026, 8)),
        fn (DatadisClient $c) => $c->getConsumptionData(Scenario::cups(), '2', 5, Month::of(2026, 8), authorizedNif: Nif::fromString('00000000T')),
    ],
    'maximum power keys reactive data too' => [
        fn (DatadisClient $c, DateTimeImmutable $now) => $c->rememberMaxPower($now->modify('-1 hour'), Scenario::cups(), '2', Month::of(2026, 8)),
        fn (DatadisClient $c) => $c->getReactiveData(Scenario::cups(), '2', Month::of(2026, 8)),
    ],
    'without authorizedNif for maximum power' => [
        fn (DatadisClient $c, DateTimeImmutable $now) => $c->rememberReactiveData($now->modify('-1 hour'), Scenario::cups(), '2', Month::of(2026, 8), authorizedNif: Nif::fromString('00000000T')),
        fn (DatadisClient $c) => $c->getMaxPower(Scenario::cups(), '2', Month::of(2026, 8)),
    ],
]);

it('keeps queries that differ apart', function (Closure $call) {
    [$client, $http, $clock] = rememberingClient();
    $client->rememberConsumptionData($clock->now()->modify('-1 hour'), Scenario::cups(), '2', 5, Month::of(2026, 8));
    $http->queue(Responses::datadis('{"timeCurve":[],"distributorError":[]}'));

    $call($client);

    expect($http->requests())->toHaveCount(2);
})->with([
    'another month' => [fn (DatadisClient $c) => $c->getConsumptionData(Scenario::cups(), '2', 5, Month::of(2026, 7))],
    'quarter-hourly' => [fn (DatadisClient $c) => $c->getConsumptionData(Scenario::cups(), '2', 5, Month::of(2026, 8), measurementType: MeasurementType::QuarterHourly)],
    'for a holder' => [fn (DatadisClient $c) => $c->getConsumptionData(Scenario::cups(), '2', 5, Month::of(2026, 8), authorizedNif: Nif::fromString('00000000T'))],
]);

it('remembers a month that has left the window Datadis serves since it was sent', function () {
    [$client, , $clock] = rememberingClient();
    $boundary = Month::current($clock->now())->addMonths(-Month::HISTORY_MONTHS);

    expect($client->rememberMaxPower($clock->now()->modify('-1 hour'), Scenario::cups(), '2', $boundary))->toBeTrue();
});

it('refuses what it would refuse to send, and an attempt in the future, without touching the ledger', function (Closure $remember) {
    [$client, $http, $clock] = rememberingClient();

    expect(fn () => $remember($client, $clock->now()))->toThrow(InvalidRequestException::class)->and($http->requests())->toBe([]);
})->with([
    'more than ten minutes ahead' => [fn (DatadisClient $c, DateTimeImmutable $now) => $c->rememberMaxPower($now->modify('+11 minutes'), Scenario::cups(), '2', Month::of(2026, 8))],
    'a reversed range' => [fn (DatadisClient $c, DateTimeImmutable $now) => $c->rememberMaxPower($now->modify('-1 hour'), Scenario::cups(), '2', Month::of(2026, 8), Month::of(2026, 7))],
    'a wrong point type' => [fn (DatadisClient $c, DateTimeImmutable $now) => $c->rememberConsumptionData($now->modify('-1 hour'), Scenario::cups(), '2', 9, Month::of(2026, 8))],
    'a wrong distributor code' => [fn (DatadisClient $c, DateTimeImmutable $now) => $c->rememberMaxPower($now->modify('-1 hour'), Scenario::cups(), '', Month::of(2026, 8))],
    'another holder on a holder client' => [fn (DatadisClient $c, DateTimeImmutable $now) => $c->forHolder(Nif::fromString('00000000T'))->rememberMaxPower($now->modify('-1 hour'), Scenario::cups(), '2', Month::of(2026, 8), authorizedNif: Nif::fromString('X0000000T'))],
]);

it('refuses to remember on a client without a ledger of yours, which would keep it in its own memory only', function (Closure $remember) {
    $s = Scenario::make();

    expect(fn () => $remember($s->client, $s->clock->now()->modify('-1 hour')))->toThrow(ConfigurationException::class, 'RequestLedger your workers share')
        ->and($s->http->requests())->toBe([]);
})->with([
    'consumption' => [fn (DatadisClient $c, DateTimeImmutable $at) => $c->rememberConsumptionData($at, Scenario::cups(), '2', 5, Month::of(2026, 8))],
    'maximum power' => [fn (DatadisClient $c, DateTimeImmutable $at) => $c->rememberMaxPower($at, Scenario::cups(), '2', Month::of(2026, 8))],
    'reactive' => [fn (DatadisClient $c, DateTimeImmutable $at) => $c->rememberReactiveData($at, Scenario::cups(), '2', Month::of(2026, 8))],
]);

it('looks without sending or claiming, and tells what the call then does', function () {
    [$client, $http, $clock] = rememberingClient();
    $supply = DatadisWithTheRule::supply();

    expect($client->consumptionDataBlockedUntilOf($supply, Month::of(2026, 8)))->toBeNull()
        ->and($client->maxPowerBlockedUntilOf($supply, Month::of(2026, 8)))->toBeNull()
        ->and($http->requests())->toBe([]);

    $http->queue(Responses::datadis('{"timeCurve":[],"distributorError":[]}'));
    $client->getConsumptionDataOf($supply, Month::of(2026, 8));

    expect($client->consumptionDataBlockedUntilOf($supply, Month::of(2026, 8))?->getTimestamp())->toBe($clock->now()->getTimestamp() + RequestLedger::WINDOW_SECONDS)
        ->and($client->consumptionDataBlockedUntilOf($supply, Month::of(2026, 7)))->toBeNull();
});

it('remembers a query of a supply as listed', function (Closure $remember, Closure $look) {
    [$client, $http, $clock] = rememberingClient();
    $supply = DatadisWithTheRule::supply();
    $sentAt = $clock->now()->modify('-2 hours');

    expect($remember($client, $supply, $sentAt))->toBeTrue()
        ->and($look($client, $supply)?->getTimestamp())->toBe($sentAt->getTimestamp() + RequestLedger::WINDOW_SECONDS)
        ->and($http->requests())->toBe([]);
})->with([
    'consumption' => [fn ($c, $s, $at) => $c->rememberConsumptionDataOf($at, $s, Month::of(2026, 8)), fn ($c, $s) => $c->consumptionDataBlockedUntilOf($s, Month::of(2026, 8))],
    'max power, which keys reactive data too' => [fn ($c, $s, $at) => $c->rememberMaxPowerOf($at, $s, Month::of(2026, 8)), fn ($c, $s) => $c->reactiveDataBlockedUntilOf($s, Month::of(2026, 8))],
    'reactive, which keys maximum power too' => [fn ($c, $s, $at) => $c->rememberReactiveDataOf($at, $s, Month::of(2026, 8)), fn ($c, $s) => $c->maxPowerBlockedUntilOf($s, Month::of(2026, 8))],
]);

it('refuses reactive data on a v1 client to remember or look up, as it refuses to send it', function (Closure $call) {
    $http = new FakeHttpClient;
    $clock = Scenario::clock();
    $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter(Scenario::SECRET), $clock);
    $client = new DatadisClient(Scenario::config(), http: $http, version: ApiVersion::V1, clock: $clock, ledger: $ledger);

    expect(fn () => $call($client, $clock->now()))->toThrow(UnsupportedOperationException::class)
        ->and($client->maxPowerBlockedUntil(Scenario::cups(), '2', Month::of(2026, 8)))->toBeNull()
        ->and($http->requests())->toBe([]);
})->with([
    'remember' => [fn ($c, $now) => $c->rememberReactiveData($now->modify('-1 hour'), Scenario::cups(), '2', Month::of(2026, 8))],
    'remember a supply' => [fn ($c, $now) => $c->rememberReactiveDataOf($now->modify('-1 hour'), DatadisWithTheRule::supply(), Month::of(2026, 8))],
    'look up' => [fn ($c) => $c->reactiveDataBlockedUntil(Scenario::cups(), '2', Month::of(2026, 8))],
    'look up a supply' => [fn ($c) => $c->reactiveDataBlockedUntilOf(DatadisWithTheRule::supply(), Month::of(2026, 8))],
]);
