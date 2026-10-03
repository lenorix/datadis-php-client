<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\DatadisWithTheRule;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\Scenario;

/*
 * A sync that runs every day: wherever in the first hours of the day each run reaches Datadis,
 * and however far Datadis's clock is from ours, getLatest...Of() never sends a query Datadis
 * refuses.
 */

it('brings the current month every day of a daily sync, however much earlier each run starts and however often it runs again', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            // For each day: when the first run reaches Datadis after 00:00 Madrid, how many more
            // runs follow the same day (a retry, a second scheduler), and how far apart.
            Generators::vector(60, Generators::tuple(Generators::choose(0, 3 * 3600), Generators::choose(0, 2), Generators::choose(60, 6 * 3600))),
            Generators::choose(-600, 600),   // how far Datadis's clock is from ours
            Generators::bool(),              // a shared ledger, or a new process every run
        )
        ->then(function (array $days, int $skew, bool $shared) {
            $zone = new DateTimeZone('Europe/Madrid');
            $clock = new FrozenClock(new DateTimeImmutable('2026-09-20', $zone));
            $datadis = new DatadisWithTheRule($clock, $skew);
            $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter(Scenario::SECRET), $clock);
            $start = $clock->now();
            $run = function () use ($shared, $datadis, $ledger): void {
                $client = $datadis->client($shared ? $ledger : null);
                $client->getLatestConsumptionDataOf(DatadisWithTheRule::supply());
                $client->getLatestMaxPowerOf(DatadisWithTheRule::supply());
            };

            foreach ($days as $day => [$offset, $again, $gap]) {
                $clock->advance($start->modify("+{$day} days")->getTimestamp() + $offset - $clock->now()->getTimestamp());

                // The first run of every day must get its data: it throws otherwise.
                $run();

                for ($i = 0; $i < $again; $i++) {
                    $clock->advance($gap);

                    try {
                        $run();
                    } catch (RepetitionWindowException) {
                        // a run again on the same day is refused, locally with a shared ledger
                    }
                }
            }

            if ($shared) {
                expect($datadis->refused)->toBe(0);
            }
        });
});
