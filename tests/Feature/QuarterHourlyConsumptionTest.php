<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;

/*
 * No quarter-hourly answer of a point type 1, 2 or 3 supply has been captured. Two conventions are
 * possible: the end of each quarter (00:15..24:00), or the hour that ends followed by the minute the
 * quarter starts (01:00..24:45), which one implementation in production assumes. The client
 * recognises either in each answer.
 */

/**
 * The labels of a real day in Madrid, in either convention, from the actual quarters of that day.
 *
 * @return list<string>
 */
function quarterLabels(string $date, bool $hourEnding): array
{
    $zone = new DateTimeZone('Europe/Madrid');
    $midnight = new DateTimeImmutable($date, $zone);
    $next = $midnight->modify('+1 day')->getTimestamp();
    $labels = [];

    for ($t = $midnight->getTimestamp(); $t < $next; $t += 900) {
        if ($hourEnding) {
            $start = (new DateTimeImmutable('@'.$t))->setTimezone($zone);
            $labels[] = sprintf('%02d:%s', (int) $start->format('G') + 1, $start->format('i'));
        } else {
            // The wall clock at the end, read with the offset of the quarter itself, like the hourly labels.
            $offset = $zone->getOffset(new DateTimeImmutable('@'.($t + 899)));
            $labels[] = $t + 900 === $next ? '24:00' : gmdate('H:i', $t + 900 + $offset);
        }
    }

    return $labels;
}

function readQuarterDay(string $date, array $labels): array
{
    $s = Scenario::make();
    $s->http->queue(Responses::datadis(Payloads::envelope('timeCurve', Payloads::hourlyRows($date, $labels))));
    [$year, $month] = array_map('intval', explode('/', $date));

    return $s->client->getConsumptionData(Cups::fromString(Scenario::CUPS), '2', 1, Month::of($year, $month), measurementType: MeasurementType::QuarterHourly)->records;
}

it('places every quarter of a day in either convention, change days included', function (string $date, bool $hourEnding, int $quarters) {
    $readings = readQuarterDay(str_replace('-', '/', $date), quarterLabels($date, $hourEnding));
    $zone = new DateTimeZone('Europe/Madrid');

    expect($readings)->toHaveCount($quarters)
        ->and($readings[0]->start?->getTimestamp())->toBe((new DateTimeImmutable($date, $zone))->getTimestamp())
        ->and(end($readings)->end?->getTimestamp())->toBe((new DateTimeImmutable($date, $zone))->modify('+1 day')->getTimestamp());

    foreach ($readings as $i => $reading) {
        expect($reading->end->getTimestamp() - $reading->start->getTimestamp())->toBe(900);

        if ($i > 0) {
            expect($reading->start->getTimestamp())->toBe($readings[$i - 1]->end->getTimestamp());
        }
    }
})->with([
    'end of the quarter, a normal day' => ['2025-11-04', false, 96],
    'hour ending, a normal day' => ['2025-11-04', true, 96],
    'end of the quarter, the 25 hour day' => ['2025-10-26', false, 100],
    'hour ending, the 25 hour day' => ['2025-10-26', true, 100],
    'end of the quarter, the 23 hour day' => ['2026-03-29', false, 92],
    'hour ending, the 23 hour day' => ['2026-03-29', true, 92],
]);

it('keeps the rows without an interval when an answer cannot tell the convention', function () {
    $readings = readQuarterDay('2025/11/04', ['10:00', '10:15', '10:30', '10:45']);

    expect($readings)->toHaveCount(4)
        ->and(array_filter($readings, fn ($r) => $r->hasValidTime()))->toBe([])
        ->and($readings[1]->consumptionKWh)->not->toBeNull();
});
