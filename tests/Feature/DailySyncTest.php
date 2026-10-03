<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Tests\Support\DatadisWithTheRule;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Time\Month;

/*
 * A sync that runs every day and asks for the current month. Datadis refuses an identical query
 * for 24 hours, so a run that starts a little earlier than the day before would be refused if it
 * asked the same range again. getLatest...Of() alternates the range with the civil day.
 */

it('asks the same range every day only to be refused, which is why the range alternates', function () {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-20 00:10', new DateTimeZone('Europe/Madrid')));
    $datadis = new DatadisWithTheRule($clock);
    $month = Month::current($clock->now());

    $datadis->client()->getConsumptionDataOf(DatadisWithTheRule::supply(), $month);
    $clock->advance(86400 - 60);

    expect(fn () => $datadis->client()->getConsumptionDataOf(DatadisWithTheRule::supply(), $month))->toThrow(RepetitionWindowException::class)
        ->and($datadis->refused)->toBe(1);
});

it('refuses a second run on the same day, which leaves the next day free even when it runs earlier', function () {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-16 01:00', new DateTimeZone('Europe/Madrid')));
    $datadis = new DatadisWithTheRule($clock);
    $client = $datadis->client();

    $client->getLatestConsumptionDataOf(DatadisWithTheRule::supply());
    $clock->advance(3600);

    expect(fn () => $client->getLatestConsumptionDataOf(DatadisWithTheRule::supply()))->toThrow(RepetitionWindowException::class);

    // The next day at 00:30, half an hour earlier than the first run.
    $clock->advance(86400 - 5400);
    $client->getLatestConsumptionDataOf(DatadisWithTheRule::supply());

    expect(array_map(fn (string $k) => explode('|', $k)[2].'-'.explode('|', $k)[3], $datadis->sent))->toBe(['2026/09-2026/09', '2026/08-2026/09'])
        ->and($datadis->refused)->toBe(0);
});

it('refreshes a contract that started this month every other day, and refuses one with nothing to refresh', function () {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-16 01:00', new DateTimeZone('Europe/Madrid')));
    $datadis = new DatadisWithTheRule($clock);

    $datadis->client()->getLatestMaxPowerOf(DatadisWithTheRule::supply('2026/09/10'));
    $clock->advance(86400 - 60);

    expect(fn () => $datadis->client()->getLatestMaxPowerOf(DatadisWithTheRule::supply('2026/09/10')))->toThrow(RepetitionWindowException::class)
        ->and(fn () => $datadis->client()->getLatestMaxPowerOf(DatadisWithTheRule::supply('2020/01/01', '2026/08/31')))->toThrow(InvalidRequestException::class, 'no data to refresh')
        ->and($datadis->sent)->toHaveCount(2);
});

it('says which months the daily call asked for, also when they came back empty', function (string $at, string $from) {
    $clock = new FrozenClock(new DateTimeImmutable($at, new DateTimeZone('Europe/Madrid')));
    $datadis = new DatadisWithTheRule($clock);

    $readings = $datadis->client()->getLatestConsumptionDataOf(DatadisWithTheRule::supply());
    $peaks = $datadis->client()->getLatestMaxPowerOf(DatadisWithTheRule::supply());

    expect($readings->isEmpty())->toBeTrue()
        ->and($readings->startDate?->format())->toBe($from)->and($readings->endDate?->format())->toBe('2026/09')
        ->and($peaks->startDate?->format())->toBe($from)->and($peaks->endDate?->format())->toBe('2026/09');
})->with([
    'an odd day: the previous and the current month' => ['2026-09-15 06:00', '2026/08'],
    'an even day: the current month' => ['2026-09-16 06:00', '2026/09'],
]);

it('says which months a refused daily call asked for, refused by the ledger or by Datadis', function (string $at, string $from, bool $byDatadis) {
    $clock = new FrozenClock(new DateTimeImmutable($at, new DateTimeZone('Europe/Madrid')));
    $datadis = new DatadisWithTheRule($clock);
    $first = $datadis->client();
    $first->getLatestConsumptionDataOf(DatadisWithTheRule::supply());
    $clock->advance(3600);
    // The same client refuses it itself; a new process without a shared ledger sends it, and Datadis refuses it.
    $again = $byDatadis ? $datadis->client() : $first;

    try {
        $again->getLatestConsumptionDataOf(DatadisWithTheRule::supply());
    } catch (RepetitionWindowException $e) {
        expect($e->startDate?->format())->toBe($from)
            ->and($e->endDate?->format())->toBe('2026/09')
            ->and($e->httpStatus)->toBe($byDatadis ? 429 : null);

        return;
    }

    throw new LogicException('Expected a RepetitionWindowException.');
})->with([
    'an odd day, by the ledger' => ['2026-09-15 06:00', '2026/08', false],
    'an even day, by the ledger' => ['2026-09-16 06:00', '2026/09', false],
    'an odd day, by Datadis' => ['2026-09-15 06:00', '2026/08', true],
    'an even day, by Datadis' => ['2026-09-16 06:00', '2026/09', true],
]);
