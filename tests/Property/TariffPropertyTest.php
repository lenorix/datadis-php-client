<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Calendar\NationalHolidays;
use Lenorix\DatadisClient\Calendar\Territory;
use Lenorix\DatadisClient\Support\TextNormalizer;
use Lenorix\DatadisClient\Tariff\AccessFareParser;
use Lenorix\DatadisClient\Tariff\AccessTariff;
use Lenorix\DatadisClient\Tariff\FixedSchedulePeriods;

$fragments = [
    'BAJA TENSION', 'Baja tensión', 'baja  tension', 'y', 'POTENCIA', 'potencia', '<=', '>', '>=', '<', '≤', '≥',
    '15 kW', '15kw', '1 kV', '30 kV', '72,5 kV', '72.5 kV', '145 kV', 'menor o igual', 'superior', 'mayor o igual',
    '2.0TD', '3.0 TD', '6.1TD', '6.4TD', 'PEAJE ATR', '-', 'TARIFA', "\n", '  ',
];

it('never throws on any description', function () use ($fragments) {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::seq(Generators::elements(...$fragments)), Generators::string())
        ->then(function (array $parts, string $noise) {
            expect(fn () => AccessFareParser::parse(implode(' ', $parts).$noise))->not->toThrow(Throwable::class);
        });
});

it('does not depend on case, accents or spacing', function () use ($fragments) {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::seq(Generators::elements(...$fragments)), Generators::elements(' ', '  ', "\t", ' '."\n"))
        ->then(function (array $parts, string $glue) {
            $text = implode(' ', $parts);
            $variant = mb_strtoupper(implode($glue, $parts));

            expect(AccessFareParser::parse($variant))->toBe(AccessFareParser::parse($text))
                ->and(AccessFareParser::parse(strtolower(strtr($text, ['ó' => 'o', 'Ó' => 'O']))))->toBe(AccessFareParser::parse($text));
        });
});

it('classifies high voltage by the lower bound, with either decimal separator', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(10, 5000), Generators::elements('.', ','), Generators::elements('>=', '≥', 'mayor o igual a'))
        ->then(function (int $tenths, string $separator, string $operator) {
            $bound = $tenths / 10;
            $text = sprintf('Tension %s %d%s%d kV', $operator, intdiv($tenths, 10), $separator, $tenths % 10);

            $expected = match (true) {
                $bound < 30 => AccessTariff::T61TD,
                $bound < 72.5 => AccessTariff::T62TD,
                $bound < 145 => AccessTariff::T63TD,
                default => AccessTariff::T64TD,
            };

            expect(AccessFareParser::parse($text))->toBe($expected);
        });
});

it('normalises idempotently into lowercase single-spaced ASCII', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::oneOf(Generators::string(), Generators::elements('Ñandú  EDISTRIBUCIÓN', "Maxímetro\t\n", "\xC3\x28 broken")))
        ->then(function (string $text) {
            $once = TextNormalizer::normalize($text);

            expect(TextNormalizer::normalize($once))->toBe($once)
                ->and(preg_match('/[^\x20-\x7E]/', $once))->toBe(0)
                ->and($once)->toBe(strtolower($once))
                ->and($once)->not->toContain('  ')
                ->and($once)->toBe(trim($once));
        });
});

it('maps every hour of every day to a 2.0TD period with 8 hours each on working days', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(0, 3650), Generators::elements(...Territory::cases()))
        ->then(function (int $offset, Territory $territory) {
            $day = (new DateTimeImmutable('2020-01-01'))->modify("+{$offset} days");
            $periods = new FixedSchedulePeriods($territory);
            $byHour = array_map(fn (int $h) => $periods->periodFor($day, $h), range(0, 23));

            if (NationalHolidays::isWorkingDay($day)) {
                expect(array_count_values($byHour))->toEqual([1 => 8, 2 => 8, 3 => 8]);
            } else {
                expect(array_unique($byHour))->toBe([3]);
            }
        });
});

it('resolves every assigned province and nothing else', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(0, 99999), Generators::string())
        ->then(function (int $number, string $junk) {
            $code = sprintf('%05d', $number);
            $province = intdiv($number, 1000);

            expect(Territory::fromPostalCode($code) !== null)->toBe($province >= 1 && $province <= 52)
                ->and(Territory::fromPostalCode($junk) === null || preg_match('/^\s*\d{5}\s*$/D', $junk) === 1)->toBeTrue();
        });
});
