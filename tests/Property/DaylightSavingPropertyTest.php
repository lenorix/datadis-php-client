<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\HourLabel;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Time\QuarterHourLabel;
use Lenorix\DatadisClient\Values\Cups;

/**
 * The labels Datadis would send for a real day: walk the actual hours from local midnight to the
 * next one and label each by the wall clock at its end, `24:00` for the last.
 *
 * @return list<array{string, int}> label and UTC start of each hour
 */
function realDay(DateTimeImmutable $midnight, int $step = 3600): array
{
    $next = $midnight->modify('+1 day');
    $hours = [];

    for ($t = $midnight->getTimestamp(); $t < $next->getTimestamp(); $t += $step) {
        // The label is the wall clock at the end of the hour as read with the offset of that hour,
        // which is why the autumn day repeats 03:00 (verified against real captures).
        $offset = $midnight->getTimezone()->getOffset(new DateTimeImmutable('@'.($t + $step - 1)));
        $label = $t + $step === $next->getTimestamp() ? '24:00' : gmdate('H:i', $t + $step + $offset);
        $hours[] = [$label, $t];
    }

    return $hours;
}

it('turns every real day of any year into the exact hours it had (Madrid verified, Canary time assumed)', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::oneOf(
                Generators::map(fn (int $days) => "2020-01-01 +{$days} days", Generators::choose(0, 365 * 12)),
                // Half of the cases are change days, which random days would almost never hit.
                Generators::map(fn (array $p) => "last sunday of {$p[0]}-{$p[1]}", Generators::tuple(Generators::choose(2000, 2037), Generators::elements('03', '10'))),
            ),
            Generators::elements('Europe/Madrid', 'Atlantic/Canary'),
        )
        ->then(function (string $when, string $zoneName) {
            $zone = new DateTimeZone($zoneName);
            $midnight = (new DateTimeImmutable($when, $zone))->setTime(0, 0);
            $occurrences = [];

            $day = realDay($midnight);
            expect(count($day))->toBeIn([23, 24, 25]);

            foreach ($day as [$label, $start]) {
                $occurrence = $occurrences[$label] = ($occurrences[$label] ?? -1) + 1;
                $interval = HourLabel::parse($label)->interval($midnight, $occurrence);

                expect($interval)->not->toBeNull()
                    ->and($interval[0]->getTimestamp())->toBe($start)
                    ->and($interval[1]->getTimestamp())->toBe($start + 3600);
            }
        });
});

it('decodes the real change days through the client', function (int $year, int $month) {
    $zone = new DateTimeZone('Europe/Madrid');
    $lastSunday = new DateTimeImmutable("last sunday of {$year}-{$month}", $zone);
    $day = realDay($lastSunday);
    $s = Scenario::make();
    $s->http->queue(Responses::datadis(Payloads::envelope('timeCurve', Payloads::hourlyRows($lastSunday->format('Y/m/d'), array_column($day, 0)))));

    $readings = $s->client->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of($year, $month), Month::of($year, $month))->records;

    expect(array_map(fn ($r) => $r->start?->getTimestamp(), $readings))->toBe(array_column($day, 1));
})->with([
    'autumn 2025, 25 hours' => [2025, 10],
    'spring 2026, 23 hours' => [2026, 3],
]);

it('turns the last Sunday of any month into its quarter hours, if quarter labels follow the hourly convention (unverified)', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::map(fn (array $p) => "last sunday of {$p[0]}-{$p[1]}", Generators::tuple(Generators::choose(2000, 2037), Generators::elements('01', '03', '06', '10'))),
            Generators::elements('Europe/Madrid', 'Atlantic/Canary'),
        )
        ->then(function (string $when, string $zoneName) {
            $midnight = (new DateTimeImmutable($when, new DateTimeZone($zoneName)))->setTime(0, 0);
            $occurrences = [];

            foreach (realDay($midnight, 900) as [$label, $start]) {
                $occurrence = $occurrences[$label] = ($occurrences[$label] ?? -1) + 1;
                $interval = QuarterHourLabel::parse($label)->interval($midnight, $occurrence);

                expect($interval)->not->toBeNull()
                    ->and($interval[0]->getTimestamp())->toBe($start)
                    ->and($interval[1]->getTimestamp())->toBe($start + 900);
            }
        });
});
