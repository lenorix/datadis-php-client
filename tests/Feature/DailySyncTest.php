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

it('takes the other range on a second run the same day, and refuses a third', function () {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-16 01:00', new DateTimeZone('Europe/Madrid')));
    $datadis = new DatadisWithTheRule($clock);
    $client = $datadis->client();

    $client->getLatestConsumptionDataOf(DatadisWithTheRule::supply());
    $clock->advance(3600);
    $client->getLatestConsumptionDataOf(DatadisWithTheRule::supply());
    $clock->advance(3600);

    expect(fn () => $client->getLatestConsumptionDataOf(DatadisWithTheRule::supply()))->toThrow(RepetitionWindowException::class)
        ->and(array_map(fn (string $k) => explode('|', $k)[2].'-'.explode('|', $k)[3], $datadis->sent))->toBe(['2026/09-2026/09', '2026/08-2026/09'])
        ->and($datadis->refused)->toBe(0);
});

it('does not try the other range after Datadis itself refuses the first', function () {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-16 01:00', new DateTimeZone('Europe/Madrid')));
    $datadis = new DatadisWithTheRule($clock);
    // Another application with the same account asked the range a moment ago.
    $datadis->client()->getConsumptionDataOf(DatadisWithTheRule::supply(), Month::of(2026, 9));

    expect(fn () => $datadis->client()->getLatestConsumptionDataOf(DatadisWithTheRule::supply()))->toThrow(RepetitionWindowException::class)
        ->and($datadis->sent)->toHaveCount(2)
        ->and($datadis->refused)->toBe(1);
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
