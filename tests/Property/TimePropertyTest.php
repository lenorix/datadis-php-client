<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Time\HourLabel;
use Lenorix\DatadisClient\Time\QuarterHourLabel;

it('maps hour labels 1..24 to indexes 0..23 and rejects the rest', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(-5, 40), Generators::choose(0, 99))
        ->then(function (int $hour, int $minute) {
            $label = sprintf('%02d:%02d', $hour, $minute);
            $parsed = HourLabel::tryParse($label);

            if ($hour >= 1 && $hour <= 24 && $minute === 0) {
                expect($parsed?->index())->toBe($hour - 1);
            } else {
                expect($parsed)->toBeNull();
            }
        });
});

it('never accepts a quarter label off the quarter grid', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(0, 30), Generators::choose(0, 99))
        ->then(function (int $hour, int $minute) {
            $parsed = QuarterHourLabel::tryParse(sprintf('%02d:%02d', $hour, $minute));
            $total = $hour * 60 + $minute;
            $valid = $minute % 15 === 0 && $minute < 60 && $total >= 15 && $total <= 1440;

            expect($parsed !== null)->toBe($valid);
        });
});
