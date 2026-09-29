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

it('gives every hourly interval a length of one hour on a normal day', function () {
    $zone = new DateTimeZone('Europe/Madrid');
    $day = new DateTimeImmutable('2025-01-15', $zone);

    $this->limitTo(24)
        ->forAll(Generators::choose(1, 24))
        ->then(function (int $hour) use ($day) {
            [$start, $end] = HourLabel::parse(sprintf('%02d:00', $hour))->interval($day);

            expect($end->getTimestamp() - $start->getTimestamp())->toBe(3600);
        });
});

it('gives every quarter-hour interval a length of 15 minutes on a normal day', function () {
    $day = new DateTimeImmutable('2025-01-15', new DateTimeZone('Europe/Madrid'));

    $this->limitTo(96)
        ->forAll(Generators::choose(1, 96))
        ->then(function (int $quarter) use ($day) {
            $minutes = $quarter * 15;
            $label = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            [$start, $end] = QuarterHourLabel::parse($label)->interval($day);

            expect($end->getTimestamp() - $start->getTimestamp())->toBe(900);
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
