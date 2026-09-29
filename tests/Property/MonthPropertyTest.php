<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Time\Month;

it('round-trips through the wire format', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(1, 9999), Generators::choose(1, 12))
        ->then(function (int $year, int $month) {
            $original = Month::of($year, $month);

            expect(Month::fromString($original->format())->equals($original))->toBeTrue()
                ->and($original->format())->toMatch('/^\d{4}\/(0[1-9]|1[0-2])$/');
        });
});

it('adds and subtracts months as inverse operations', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(100, 9000), Generators::choose(1, 12), Generators::choose(-600, 600))
        ->then(function (int $year, int $month, int $delta) {
            $start = Month::of($year, $month);
            $moved = $start->addMonths($delta);

            expect($moved->addMonths(-$delta)->equals($start))->toBeTrue()
                ->and($moved->diffInMonths($start))->toBe($delta);
        });
});

it('builds sequences that are consecutive and complete', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(100, 9000), Generators::choose(1, 12), Generators::choose(0, 60))
        ->then(function (int $year, int $month, int $length) {
            $from = Month::of($year, $month);
            $to = $from->addMonths($length);
            $sequence = Month::sequence($from, $to);

            expect($sequence)->toHaveCount($length + 1)
                ->and($sequence[0]->equals($from))->toBeTrue()
                ->and($sequence[array_key_last($sequence)]->equals($to))->toBeTrue();

            for ($i = 1; $i < count($sequence); $i++) {
                expect($sequence[$i]->diffInMonths($sequence[$i - 1]))->toBe(1);
            }
        });
});
