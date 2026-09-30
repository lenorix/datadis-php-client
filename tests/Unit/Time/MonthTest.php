<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Time\Month;

it('parses and formats the wire format', function () {
    $month = Month::fromString('2025/03');

    expect($month->year)->toBe(2025)
        ->and($month->month)->toBe(3)
        ->and($month->format())->toBe('2025/03')
        ->and((string) $month)->toBe('2025/03');
});

it('rejects anything that is not YYYY/MM', function (string $value) {
    Month::fromString($value);
})->with([
    'dashes' => '2025-03',
    'unpadded month' => '2025/3',
    'month 13' => '2025/13',
    'month 00' => '2025/00',
    'day level' => '2025/03/01',
    'leading space' => ' 2025/03',
    'trailing newline' => "2025/03\n",
    'two digit year' => '25/03',
    'empty' => '',
])->throws(InvalidArgumentException::class);

it('rejects invalid components in of()', function (int $year, int $month) {
    Month::of($year, $month);
})->with([[2025, 0], [2025, 13], [0, 1], [10000, 1]])->throws(InvalidArgumentException::class);

it('builds a month from a date', function () {
    expect(Month::fromDate(new DateTimeImmutable('2026-09-15 23:59'))->format())->toBe('2026/09');
});

it('adds months across year boundaries in both directions', function () {
    expect(Month::of(2025, 11)->addMonths(3)->format())->toBe('2026/02')
        ->and(Month::of(2025, 1)->addMonths(-1)->format())->toBe('2024/12')
        ->and(Month::of(2025, 5)->addMonths(0)->format())->toBe('2025/05')
        ->and(Month::of(2025, 5)->addMonths(-29)->format())->toBe('2022/12');
});

it('computes the difference in months', function () {
    expect(Month::of(2026, 2)->diffInMonths(Month::of(2025, 11)))->toBe(3)
        ->and(Month::of(2025, 11)->diffInMonths(Month::of(2026, 2)))->toBe(-3);
});

it('compares months', function () {
    $a = Month::of(2025, 12);
    $b = Month::of(2026, 1);

    expect($a->isBefore($b))->toBeTrue()
        ->and($b->isAfter($a))->toBeTrue()
        ->and($a->equals(Month::of(2025, 12)))->toBeTrue()
        ->and($a->compareTo($b))->toBeLessThan(0)
        ->and($a->isAfter($a))->toBeFalse();
});

it('accepts the last 24 months and refuses the boundary month and the future', function () {
    $now = new DateTimeImmutable('2026-09-15');

    expect(Month::of(2024, 9)->isWithinHistory($now))->toBeFalse()
        ->and(Month::of(2024, 10)->isWithinHistory($now))->toBeTrue()
        ->and(Month::of(2026, 9)->isWithinHistory($now))->toBeTrue()
        ->and(Month::of(2026, 10)->isWithinHistory($now))->toBeFalse();
});

it('knows what is in the future', function () {
    $now = new DateTimeImmutable('2026-09-15');

    expect(Month::of(2026, 9)->isFuture($now))->toBeFalse()
        ->and(Month::of(2026, 10)->isFuture($now))->toBeTrue();
});

it('lists an inclusive sequence of months', function () {
    $months = Month::sequence(Month::of(2025, 11), Month::of(2026, 2));

    expect(array_map(fn (Month $m) => $m->format(), $months))->toBe(['2025/11', '2025/12', '2026/01', '2026/02']);
});

it('returns one month for an equal start and end and refuses a reversed range', function () {
    expect(Month::sequence(Month::of(2025, 1), Month::of(2025, 1)))->toHaveCount(1);
    Month::sequence(Month::of(2025, 2), Month::of(2025, 1));
})->throws(InvalidArgumentException::class);

it('accepts the first and last representable years and is not before itself', function () {
    expect(Month::of(1, 1)->format())->toBe('0001/01')
        ->and(Month::of(9999, 12)->format())->toBe('9999/12')
        ->and(Month::of(2026, 1)->isBefore(Month::of(2026, 1)))->toBeFalse();
});

it('knows the current month on the Madrid calendar', function () {
    $lateCanary = new DateTimeImmutable('2026-09-30 23:30:00', new DateTimeZone('Atlantic/Canary'));

    expect(Month::current($lateCanary)->format())->toBe('2026/10')
        ->and(Month::of(2026, 10)->isFuture($lateCanary))->toBeFalse()
        ->and(Month::of(2024, 10)->isWithinHistory($lateCanary))->toBeFalse();
});
