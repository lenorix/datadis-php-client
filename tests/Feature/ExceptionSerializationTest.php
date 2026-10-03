<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;

/** What a call throws, with arguments recorded in traces as PHP does by default. */
function thrownBy(Closure $call): DatadisException
{
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $call();
    } catch (DatadisException $e) {
        return $e;
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    throw new LogicException('Expected a DatadisException.');
}

$holder = '00000001R';

it('serializes every failure, for a queued job or a cache, without a CUPS or a NIF', function (Closure $failure) use ($holder) {
    $e = thrownBy($failure());
    $copy = unserialize(serialize($e));

    expect(serialize($e))->not->toContain(Scenario::CUPS)->not->toContain($holder)
        ->and($copy)->toBeInstanceOf($e::class)
        ->and($copy->getMessage())->toBe($e->getMessage())
        ->and($copy->detail)->toBe($e->detail)
        ->and($copy->httpStatus)->toBe($e->httpStatus)
        ->and($copy->endpoint)->toBe($e->endpoint)
        ->and($copy->requestSent)->toBe($e->requestSent)
        ->and($copy->getTrace())->toBe([])
        ->and($copy->getPrevious()?->getMessage())->toBe($e->getPrevious()?->getMessage());
})->with([
    'a refused range' => [fn () => fn () => Scenario::make()->client->getConsumptionData(Cups::fromString(Scenario::CUPS), '2', 9, Month::of(2026, 1), authorizedNif: Nif::fromString('00000001R'))],
    'a guard refusal' => [function () {
        $s = Scenario::make(ledger: fn ($clock) => Scenario::ledger($clock));
        $s->http->queue(Responses::datadis('{"maxPower":[],"distributorError":[]}'));
        $s->client->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 1), authorizedNif: Nif::fromString('00000001R'));

        return fn () => $s->client->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 1), authorizedNif: Nif::fromString('00000001R'));
    }],
    'a transport failure' => [function () {
        $s = Scenario::make();
        $s->http->queue(new ConnectException('down', new Request('GET', 'https://datadis.test')));

        return fn () => $s->client->getContractDetail(Cups::fromString(Scenario::CUPS), '2', Nif::fromString('00000001R'));
    }],
    'an unreadable answer' => [function () {
        $s = Scenario::make();
        $s->http->queue(Responses::datadis('{"x":1}'));

        return fn () => $s->client->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 1));
    }],
    'a server failure' => [function () {
        $s = Scenario::make();
        $s->http->queue(Responses::text('', 503));

        return fn () => $s->client->getContractDetail(Cups::fromString(Scenario::CUPS), '2');
    }],
]);

it('keeps the fields of its own kind when a failure is unserialized', function () {
    $s = Scenario::make(ledger: fn ($clock) => Scenario::ledger($clock));
    $s->http->queue(Responses::datadis('{"maxPower":[],"distributorError":[]}'));
    $s->client->getMaxPower(Scenario::cups(), '2', Month::of(2026, 1));
    $e = thrownBy(fn () => $s->client->getMaxPower(Scenario::cups(), '2', Month::of(2026, 1)));

    $copy = unserialize(serialize($e));

    expect($copy)->toBeInstanceOf(RepetitionWindowException::class)
        ->and($copy->availableAt)->toEqual($e->availableAt)
        ->and($copy->lastAttemptAt)->toEqual($e->lastAttemptAt)
        ->and($copy->startDate)->toEqual($e->startDate);
});

it('says plainly that a client cannot be serialized', function () {
    expect(fn () => serialize(Scenario::make()->client))->toThrow(LogicException::class, 'cannot be serialized');
});
