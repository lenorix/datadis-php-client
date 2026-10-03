<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\ContractDetail;
use Lenorix\DatadisClient\Support\Decimal;
use Lenorix\DatadisClient\Tariff\AccessFareParser;
use Lenorix\DatadisClient\Tariff\AccessTariff;
use Lenorix\DatadisClient\Tariff\ChainTariffResolver;
use Lenorix\DatadisClient\Tariff\PatternTariffResolver;
use Lenorix\DatadisClient\Tariff\StandardTariffResolver;
use Lenorix\DatadisClient\Tariff\TariffResolver;

/*
 * Datadis has no tariff field: accessFare and codeFare are text each company writes its own way.
 * The contract keeps them as received, the package reads them with every signal it has, and an
 * application can read them its own way.
 */

function contractOf(array $fields, int $powers): ContractDetail
{
    return ContractDetail::fromRow(['cups' => 'ES0000000000000000AA', 'contractedPowerkW' => array_fill(0, $powers, 5.0)] + $fields, new DateTimeZone('Europe/Madrid'))
        ?? throw new LogicException('Not a contract row.');
}

it('reads the tariff from the description, the code and the number of powers, never guessing', function (array $fields, int $powers, ?AccessTariff $expected) {
    expect(contractOf($fields, $powers)->tariff())->toBe($expected);
})->with([
    'the real 2T code alone' => [['codeFare' => '2T'], 2, AccessTariff::T20TD],
    'the code in lower case, with spaces' => [['codeFare' => ' 2t '], 2, AccessTariff::T20TD],
    'a CNMC code' => [['codeFare' => '019'], 6, AccessTariff::T30TD],
    'the tariff written as its code' => [['codeFare' => '2.0TD'], 2, AccessTariff::T20TD],
    'description and code agree' => [['accessFare' => 'BAJA TENSION y POTENCIA <= 15 kW', 'codeFare' => '2T'], 2, AccessTariff::T20TD],
    'description and code disagree' => [['accessFare' => 'BAJA TENSION Y POTENCIA > 15 kW', 'codeFare' => '2T'], 6, null],
    'an unknown code and a known description' => [['accessFare' => '2.0TD (Peaje de acceso 2.0TD)', 'codeFare' => '03'], 2, AccessTariff::T20TD],
    'an unknown code and six powers' => [['codeFare' => '61'], 6, null],
    'a code against the powers' => [['codeFare' => '019'], 2, null],
    'nothing but two powers' => [[], 2, AccessTariff::T20TD],
    'nothing at all' => [[], 0, null],
]);

it('keeps the description and the code exactly as received', function () {
    $contract = contractOf(['accessFare' => ' Peaje 2.0 TD ', 'codeFare' => '2t'], 2);

    expect($contract->accessFare)->toBe(' Peaje 2.0 TD ')->and($contract->codeFare)->toBe('2t');
});

it('takes the codes of your companies, added to the table or instead of it', function () {
    $contract = contractOf(['codeFare' => 'PEAJE-3'], 6);

    expect($contract->tariff())->toBeNull()
        ->and($contract->tariff(new StandardTariffResolver(['PEAJE-3' => AccessTariff::T30TD] + StandardTariffResolver::CODES)))->toBe(AccessTariff::T30TD)
        ->and(contractOf(['codeFare' => '2T'], 2)->tariff(new StandardTariffResolver([])))->toBe(AccessTariff::T20TD);
});

it('reads the tariff with patterns of your own, on the fields you choose, in order', function () {
    $resolver = new PatternTariffResolver(['/peaje\s*tres/i' => AccessTariff::T30TD, '/^62$/' => AccessTariff::T62TD], ['codeFare', 'accessFare']);

    expect(contractOf(['accessFare' => 'Peaje tres periodos'], 6)->tariff($resolver))->toBe(AccessTariff::T30TD)
        ->and(contractOf(['codeFare' => '62', 'accessFare' => 'Peaje tres periodos'], 6)->tariff($resolver))->toBe(AccessTariff::T62TD)
        ->and(contractOf(['tension' => 'Peaje tres'], 6)->tariff($resolver))->toBeNull()
        ->and(contractOf(['tension' => 'Peaje tres'], 6)->tariff(new PatternTariffResolver(['/peaje\s*tres/i' => AccessTariff::T30TD], ['tension'])))->toBe(AccessTariff::T30TD);
});

it('refuses a pattern that is not a regular expression', function () {
    new PatternTariffResolver(['not a pattern' => AccessTariff::T20TD]);
})->throws(InvalidArgumentException::class);

it('asks resolvers in order and takes the first answer', function () {
    $never = new class implements TariffResolver
    {
        public function resolve(ContractDetail $contract): ?AccessTariff
        {
            return null;
        }
    };
    $chain = new ChainTariffResolver($never, new PatternTariffResolver(['/^X$/' => AccessTariff::T64TD]), new StandardTariffResolver);

    expect(contractOf(['codeFare' => 'X'], 6)->tariff($chain))->toBe(AccessTariff::T64TD)
        ->and(contractOf(['codeFare' => '2T'], 2)->tariff($chain))->toBe(AccessTariff::T20TD)
        ->and(contractOf([], 6)->tariff(new ChainTariffResolver))->toBeNull();
});

it('takes codes that PHP keeps as integer keys, such as 62', function () {
    $resolver = new StandardTariffResolver(['62' => AccessTariff::T62TD] + StandardTariffResolver::CODES);

    expect(contractOf(['codeFare' => '62'], 6)->tariff($resolver))->toBe(AccessTariff::T62TD)
        ->and(contractOf(['codeFare' => 'ZZ'], 6)->tariff($resolver))->toBeNull();
});

it('takes the tariffs of a configuration file by name', function () {
    $resolver = new PatternTariffResolver(['/peaje\s*tres/i' => '3.0TD']);

    expect(contractOf(['accessFare' => 'Peaje tres periodos'], 6)->tariff($resolver))->toBe(AccessTariff::T30TD);
});

it('refuses a configuration it could only fail on later', function (Closure $build, string $message) {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a tariff that does not exist' => [fn () => new PatternTariffResolver(['/x/' => '9.9TD']), 'Not an access tariff'],
    'a field that is not a text field' => [fn () => new PatternTariffResolver(['/x/' => AccessTariff::T20TD], ['openEnded']), 'Not a text field'],
    'a misspelt field' => [fn () => new PatternTariffResolver(['/x/' => AccessTariff::T20TD], ['acessFare']), 'Not a text field'],
]);

it('says so when a pattern fails on a text instead of answering null', function () {
    $resolver = new PatternTariffResolver(['/(a+)+$/' => AccessTariff::T20TD]);
    $contract = contractOf(['accessFare' => str_repeat('a', 5000).'b'], 2);
    $limit = ini_set('pcre.backtrack_limit', '1000');

    try {
        expect(fn () => $contract->tariff($resolver))->toThrow(InvalidArgumentException::class, 'failed on the accessFare');
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});

it('takes tariffs by name in the code table too, and refuses an unknown one when built', function () {
    $resolver = new StandardTariffResolver(['3T' => '3.0TD'] + StandardTariffResolver::CODES);

    expect(contractOf(['codeFare' => '3T'], 6)->tariff($resolver))->toBe(AccessTariff::T30TD)
        ->and(fn () => new StandardTariffResolver(['3T' => '9.9TD']))->toThrow(InvalidArgumentException::class, 'Not an access tariff')
        ->and(fn () => new StandardTariffResolver(['3T' => null]))->toThrow(InvalidArgumentException::class, 'Not an access tariff');
});

it('refuses a tariff of a pattern that is not text, as a YAML null or a number', function (mixed $tariff) {
    expect(fn () => new PatternTariffResolver(['/x/' => $tariff]))->toThrow(InvalidArgumentException::class, 'Not an access tariff');
})->with([[null], [20]]);

it('reads a CNMC code sent as a number, without its leading zero', function () {
    expect(contractOf(['codeFare' => 19], 6)->tariff())->toBe(AccessTariff::T30TD);
});

it('does not read a tariff inside a longer number, and takes a decimal comma', function (string $fare, ?AccessTariff $tariff) {
    expect(AccessFareParser::parse($fare))->toBe($tariff);
})->with([
    '12.0TD' => ['12.0TD', null],
    '16.1TD' => ['16.1TD', null],
    '2,0TD' => ['2,0TD', AccessTariff::T20TD],
    '6,1 TD' => ['6,1 TD', AccessTariff::T61TD],
]);

it('takes the misspelt key when the right one is empty', function () {
    expect(contractOf(['accessFare' => '', 'accesFare' => '3.0TD'], 6)->accessFare)->toBe('3.0TD');
});

it('reads numbers with spaces around them, as whole numbers are read', function () {
    expect(Decimal::tryOf(' 5.5 ', 3))->toBe('5.500');
});
