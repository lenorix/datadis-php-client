<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\ConsumptionReading;
use Lenorix\DatadisClient\Time\BillingCycle;
use Lenorix\DatadisClient\Time\BillingPeriod;
use Lenorix\DatadisClient\Values\MeasurementType;

$madrid = new DateTimeZone('Europe/Madrid');
$span = fn (BillingPeriod $p) => $p->start->format('Y-m-d').'..'.$p->lastDay()->format('Y-m-d');
$reading = fn (string $date, string $time, float $kWh) => ConsumptionReading::fromRow(['date' => $date, 'time' => $time, 'consumptionKWh' => $kWh, 'obtainMethod' => 'Real'], $madrid, MeasurementType::Hourly);

it('takes the days of an invoice, both included, on the Madrid calendar', function () use ($span) {
    $period = BillingPeriod::between(new DateTimeImmutable('2026-08-15 18:00'), new DateTimeImmutable('2026-09-14'));

    expect($span($period))->toBe('2026-08-15..2026-09-14')
        ->and($period->start->format(DATE_ATOM))->toBe('2026-08-15T00:00:00+02:00')
        ->and($period->end->format(DATE_ATOM))->toBe('2026-09-15T00:00:00+02:00')
        ->and($period->days())->toBe(31)
        ->and(array_map(fn ($m) => $m->format(), $period->months()))->toBe(['2026/08', '2026/09']);
});

it('counts the days of a period across a clock change by the calendar', function () {
    expect(BillingPeriod::between(new DateTimeImmutable('2026-10-15'), new DateTimeImmutable('2026-11-14'))->days())->toBe(31)
        ->and(BillingPeriod::between(new DateTimeImmutable('2026-03-29'), new DateTimeImmutable('2026-03-29'))->days())->toBe(1);
});

it('refuses a period that ends before it starts', function () {
    BillingPeriod::between(new DateTimeImmutable('2026-09-15'), new DateTimeImmutable('2026-09-14'));
})->throws(InvalidArgumentException::class);

it('cycles from the same day every month', function (int $day, string $at, string $expected) use ($span, $madrid) {
    expect($span(BillingCycle::monthlyFrom($day)->periodContaining(new DateTimeImmutable($at, $madrid))))->toBe($expected);
})->with([
    'from the 15th, after it' => [15, '2026-09-20 12:00', '2026-09-15..2026-10-14'],
    'from the 15th, before it' => [15, '2026-09-10 12:00', '2026-08-15..2026-09-14'],
    'from the 15th, on it' => [15, '2026-09-15 00:00', '2026-09-15..2026-10-14'],
    'from the 1st: the calendar month' => [1, '2026-02-20', '2026-02-01..2026-02-28'],
    'from the 31st, in April' => [31, '2026-04-30', '2026-04-30..2026-05-30'],
    'from the 31st, before April ends' => [31, '2026-04-29', '2026-03-31..2026-04-29'],
    'from the 30th, in February' => [30, '2026-02-28', '2026-02-28..2026-03-29'],
    'from the 30th, in a leap February' => [30, '2028-02-29', '2028-02-29..2028-03-29'],
    'across the new year' => [20, '2027-01-05', '2026-12-20..2027-01-19'],
]);

it('judges a moment on the cycle\'s calendar, whatever its zone', function () use ($span) {
    // 23:30 UTC on the 14th is already the 15th in Madrid.
    expect($span(BillingCycle::monthlyFrom(15)->periodContaining(new DateTimeImmutable('2026-09-14 23:30 UTC'))))->toBe('2026-09-15..2026-10-14')
        ->and($span(BillingCycle::monthlyFrom(15, new DateTimeZone('UTC'))->periodContaining(new DateTimeImmutable('2026-09-14 23:30 UTC'))))->toBe('2026-08-15..2026-09-14');
});

it('lists the periods of a span and the last ended one', function () use ($span, $madrid) {
    $cycle = BillingCycle::monthlyFrom(15);

    expect(array_map($span, $cycle->periodsBetween(new DateTimeImmutable('2026-07-20'), new DateTimeImmutable('2026-09-15'))))
        ->toBe(['2026-07-15..2026-08-14', '2026-08-15..2026-09-14', '2026-09-15..2026-10-14'])
        ->and($span($cycle->lastEndedPeriod(new DateTimeImmutable('2026-09-20', $madrid))))->toBe('2026-08-15..2026-09-14')
        ->and(fn () => $cycle->periodsBetween(new DateTimeImmutable('2026-09-15'), new DateTimeImmutable('2026-09-01')))->toThrow(InvalidArgumentException::class);
});

it('refuses a day that no month has', function (int $day) {
    BillingCycle::monthlyFrom($day);
})->with([0, 32])->throws(InvalidArgumentException::class);

it('picks the readings of the period by when their hour starts, and adds them up exactly', function () use ($reading) {
    $period = BillingPeriod::between(new DateTimeImmutable('2026-09-15'), new DateTimeImmutable('2026-10-14'));
    $readings = [
        $reading('2026/09/14', '24:00', 9.0),     // 14th 23:00-24:00: the day before
        $reading('2026/09/15', '01:00', 0.1),     // the first hour
        $reading('2026/10/14', '24:00', 0.2),     // the last hour, ending at the period's end
        $reading('2026/10/15', '01:00', 9.0),     // the day after
    ];

    expect($period->readingsOf($readings))->toHaveCount(2)
        ->and($period->totalKWh($readings))->toBe('0.300')
        ->and($period->isCoveredBy($readings))->toBeTrue()
        ->and($period->isCoveredBy(array_slice($readings, 0, 2)))->toBeFalse()
        ->and($period->totalKWh([]))->toBe('0.000');
});

it('places a reading whose hour could not be read by its date', function () use ($reading) {
    $period = BillingPeriod::between(new DateTimeImmutable('2026-09-15'), new DateTimeImmutable('2026-10-14'));

    expect($period->readingsOf([$reading('2026/09/15', '99:99', 1.0), $reading('2026/09/14', '99:99', 1.0)]))->toHaveCount(1);
});
