<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Time\MonthPlanner;

it('never plans the same first range on two consecutive civil days, across months, years and daylight saving changes', function () {
    $zone = new DateTimeZone('Europe/Madrid');

    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(0, 20 * 366), Generators::choose(0, 86399), Generators::choose(0, 86399))
        ->then(function (int $day, int $firstSecond, int $nextSecond) use ($zone) {
            $midnight = (new DateTimeImmutable('2020-01-01', $zone))->modify("+{$day} days");
            $today = $midnight->modify("+{$firstSecond} seconds");
            $tomorrow = $midnight->modify('+1 day')->modify("+{$nextSecond} seconds");
            $key = fn (array $range) => $range[0]->format().'-'.$range[1]->format();

            $plan = MonthPlanner::latest($today);
            $next = MonthPlanner::latest($tomorrow);

            expect($plan)->toHaveCount(2)
                ->and($key($plan[0]))->not->toBe($key($next[0]))
                ->and($key($plan[0]))->not->toBe($key($plan[1]));

            foreach ($plan as [$from, $to]) {
                expect($to->equals(Month::current($today)))->toBeTrue()
                    ->and($to->diffInMonths($from))->toBeLessThanOrEqual(1)
                    ->and($from->isWithinHistory($today))->toBeTrue();
            }
        });
});
