<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Time\BillingCycle;

it('tiles time with consecutive periods that start on the cycle day, or the last day of a shorter month', function () {
    $madrid = new DateTimeZone('Europe/Madrid');

    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(1, 31), Generators::choose(0, 20 * 366), Generators::choose(0, 86399))
        ->then(function (int $day, int $days, int $seconds) use ($madrid) {
            $cycle = BillingCycle::monthlyFrom($day);
            $moment = (new DateTimeImmutable('2020-01-01', $madrid))->modify("+{$days} days +{$seconds} seconds");
            $period = $cycle->periodContaining($moment);
            $next = $cycle->periodContaining($period->end);

            expect($period->contains($moment))->toBeTrue()
                ->and($next->start->getTimestamp())->toBe($period->end->getTimestamp())
                ->and($period->start->format('H:i'))->toBe('00:00')
                ->and((int) $period->start->format('j'))->toBe(min($day, (int) $period->start->format('t')))
                ->and($period->days())->toBeGreaterThanOrEqual(28)->toBeLessThanOrEqual(31)
                ->and($cycle->periodContaining($period->start))->toEqual($period)
                ->and($cycle->periodContaining($period->end->modify('-1 second')))->toEqual($period);
        });
});
