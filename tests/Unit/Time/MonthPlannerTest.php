<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Time\MonthPlanner;

$now = new DateTimeImmutable('2026-09-15', new DateTimeZone('Europe/Madrid'));
$format = fn (array $ranges) => array_map(fn (array $r) => $r[0]->format().'-'.$r[1]->format(), $ranges);

it('plans one request per month by default', function () use ($now, $format) {
    expect($format(MonthPlanner::ranges(Month::of(2026, 6), Month::of(2026, 8), $now)))
        ->toBe(['2026/06-2026/06', '2026/07-2026/07', '2026/08-2026/08']);
});

it('groups months when asked', function () use ($now, $format) {
    expect($format(MonthPlanner::ranges(Month::of(2026, 1), Month::of(2026, 7), $now, 3)))
        ->toBe(['2026/01-2026/03', '2026/04-2026/06', '2026/07-2026/07']);
});

it('clamps to the window Datadis serves', function () use ($now, $format) {
    $ranges = MonthPlanner::ranges(Month::of(2020, 1), Month::of(2030, 1), $now, 12);

    expect($format($ranges))->toBe(['2024/10-2025/09', '2025/10-2026/09']);
});

it('returns nothing when the range is outside the window', function () use ($now) {
    expect(MonthPlanner::ranges(Month::of(2020, 1), Month::of(2024, 9), $now))->toBe([])
        ->and(MonthPlanner::ranges(Month::of(2026, 10), Month::of(2027, 1), $now))->toBe([]);
});

it('skips the months outside the supply contract', function () use ($now, $format) {
    $zone = new DateTimeZone('Europe/Madrid');
    $closed = Supply::fromRow(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2025/11/20', 'validDateTo' => '2026/02/03'], $zone);
    $open = Supply::fromRow(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2026/08/01', 'validDateTo' => ''], $zone);

    expect($format(MonthPlanner::ranges(Month::of(2025, 1), Month::of(2026, 9), $now, 12, $closed)))->toBe(['2025/11-2026/02'])
        ->and($format(MonthPlanner::ranges(Month::of(2025, 1), Month::of(2026, 12), $now, 1, $open)))->toBe(['2026/08-2026/08', '2026/09-2026/09']);
});

it('refuses a reversed range or an empty group', function (Closure $call) {
    $call();
})->with([
    [fn () => MonthPlanner::ranges(Month::of(2026, 3), Month::of(2026, 1), new DateTimeImmutable('2026-09-15'))],
    [fn () => MonthPlanner::ranges(Month::of(2026, 1), Month::of(2026, 3), new DateTimeImmutable('2026-09-15'), 0)],
])->throws(InvalidArgumentException::class);

it('judges the current month on the Madrid calendar whatever zone now is given in', function () use ($format) {
    $lateUtc = new DateTimeImmutable('2026-09-30 22:30:00', new DateTimeZone('UTC'));

    expect($format(MonthPlanner::ranges(Month::of(2020, 1), Month::of(2030, 1), $lateUtc, 24)))->toBe(['2024/11-2026/10']);
});

it('plans one request for any number of months per request above the history Datadis serves', function (int $monthsPerRequest) use ($now, $format) {
    expect($format(MonthPlanner::ranges(Month::of(2020, 1), Month::of(2030, 1), $now, $monthsPerRequest)))->toBe(['2024/10-2026/09']);
})->with([24, 25, 200000, PHP_INT_MAX]);

it('alternates the range of the current month with the civil day, so consecutive days differ', function () use ($format) {
    $zone = new DateTimeZone('Europe/Madrid');
    $plan = fn (string $at) => $format(MonthPlanner::latest(new DateTimeImmutable($at, $zone)));

    expect($plan('2026-09-15 00:05'))->toBe(['2026/08-2026/09'])
        ->and($plan('2026-09-16 00:05'))->toBe(['2026/09-2026/09'])
        ->and($plan('2026-09-16 23:55'))->toBe(['2026/09-2026/09'])
        ->and($plan('2026-10-01 00:05'))->toBe(['2026/09-2026/10']);
});

it('judges the civil day in Madrid, whatever the zone of now', function () use ($format) {
    // 23:30 UTC on the 15th is already the 16th in Madrid.
    expect($format(MonthPlanner::latest(new DateTimeImmutable('2026-09-15 23:30 UTC'))))
        ->toBe($format(MonthPlanner::latest(new DateTimeImmutable('2026-09-16 12:00', new DateTimeZone('Europe/Madrid')))));
});

it('keeps the plan inside the contract of the supply', function (array $contract, array $expected) use ($now, $format) {
    expect($format(MonthPlanner::latest($now, Supply::fromRow(['cups' => 'ES0000000000000000AA0A'] + $contract, new DateTimeZone('Europe/Madrid')))))->toBe($expected);
})->with([
    'started this month: only the current month' => [['validDateFrom' => '2026/09/10'], ['2026/09-2026/09']],
    'started last month: both months on an odd day' => [['validDateFrom' => '2026/08/20'], ['2026/08-2026/09']],
    'ends this month: both months on an odd day' => [['validDateFrom' => '2020/01/01', 'validDateTo' => '2026/09/30'], ['2026/08-2026/09']],
    'ended last month: nothing' => [['validDateFrom' => '2020/01/01', 'validDateTo' => '2026/08/31'], []],
    'starts next month: nothing' => [['validDateFrom' => '2026/10/01'], []],
]);
