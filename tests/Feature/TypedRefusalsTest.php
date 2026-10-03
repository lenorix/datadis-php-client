<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\NothingToRefreshException;
use Lenorix\DatadisClient\Exceptions\OutOfContractRangeException;
use Lenorix\DatadisClient\Exceptions\OutOfServedRangeException;
use Lenorix\DatadisClient\Tests\Support\DatadisWithTheRule;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;

/*
 * A sync job acts on some refusals: a month outside the contract or outside what Datadis serves is
 * never asked again, and a daily call with nothing to refresh is no failure. Each has its own type,
 * still an InvalidRequestException, so nobody has to read a message.
 */

/** @return InvalidRequestException the refusal, after checking nothing was sent */
function refusal(Scenario $s, Closure $call): InvalidRequestException
{
    try {
        $call($s->client);
    } catch (InvalidRequestException $e) {
        expect($e->requestSent)->toBeFalse()->and($s->http->requests())->toBe([]);

        return $e;
    }

    throw new LogicException('Expected an InvalidRequestException.');
}

it('names a range before or after the contract, with the month the contract starts or ends', function () {
    $s = Scenario::make();

    $before = refusal($s, fn ($c) => $c->getConsumptionDataOf(DatadisWithTheRule::supply('2026/03/15'), Month::of(2026, 2)));
    $after = refusal($s, fn ($c) => $c->getMaxPowerOf(DatadisWithTheRule::supply('2020/01/01', '2026/05/31'), Month::of(2026, 6)));

    expect($before)->toBeInstanceOf(OutOfContractRangeException::class)
        ->and($before->contractStart?->format())->toBe('2026/03')->and($before->contractEnd)->toBeNull()
        ->and($after)->toBeInstanceOf(OutOfContractRangeException::class)
        ->and($after->contractEnd?->format())->toBe('2026/05')->and($after->contractStart)->toBeNull();
});

it('names a month outside what Datadis serves, with the month', function (Closure $call, string $month) {
    $e = refusal(Scenario::make(), $call);

    expect($e)->toBeInstanceOf(OutOfServedRangeException::class)->and($e->month->format())->toBe($month);
})->with([
    'a future month' => [fn ($c) => $c->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 10)), '2026/10'],
    'the boundary month' => [fn ($c) => $c->getConsumptionData(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2024, 9), Month::of(2024, 10)), '2024/09'],
    'checked before logging in' => [fn ($c) => $c->assertServedRange(Month::of(2026, 9), Month::of(2026, 11)), '2026/11'],
]);

it('names a daily call with nothing to refresh', function (string $from, string $to) {
    $e = refusal(Scenario::make(), fn ($c) => $c->getLatestConsumptionDataOf(DatadisWithTheRule::supply($from, $to)));

    expect($e)->toBeInstanceOf(NothingToRefreshException::class);
})->with([
    'a contract that ended last month' => ['2020/01/01', '2026/08/31'],
    'a contract that starts next month' => ['2026/10/01', ''],
]);

it('keeps the other refusals plain', function () {
    $e = refusal(Scenario::make(), fn ($c) => $c->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 8), Month::of(2026, 7)));

    expect($e::class)->toBe(InvalidRequestException::class);
});
