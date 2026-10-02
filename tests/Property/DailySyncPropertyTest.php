<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\DatadisWithTheRule;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;

/*
 * A sync that runs every day: wherever in the first hours of the day each run reaches Datadis,
 * and however far Datadis's clock is from ours, getLatest...Of() never sends a query Datadis
 * refuses.
 */

it('brings the current month every day of a daily sync, however much earlier each run starts, without a single refusal', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::vector(60, Generators::choose(0, 3 * 3600)),   // when each day's run reaches Datadis, after 00:00 Madrid
            Generators::choose(-600, 600),                              // how far Datadis's clock is from ours
            Generators::bool(),                                         // a shared ledger, or a new process every day
        )
        ->then(function (array $offsets, int $skew, bool $shared) {
            $clock = new FrozenClock(new DateTimeImmutable('2026-09-20', new DateTimeZone('Europe/Madrid')));
            $datadis = new DatadisWithTheRule($clock, $skew);
            $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
            $client = $datadis->client($ledger);
            $midnight = $clock->now()->getTimestamp();

            foreach ($offsets as $day => $offset) {
                $clock->advance((new DateTimeImmutable('@'.$midnight))->setTimezone(new DateTimeZone('Europe/Madrid'))->modify("+{$day} days")->getTimestamp() + $offset - $clock->now()->getTimestamp());
                $today = $shared ? $client : $datadis->client();

                $today->getLatestConsumptionDataOf(DatadisWithTheRule::supply());
                $today->getLatestMaxPowerOf(DatadisWithTheRule::supply());
            }

            expect($datadis->refused)->toBe(0)->and($datadis->sent)->toHaveCount(2 * count($offsets));
        });
});
