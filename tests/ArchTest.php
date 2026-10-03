<?php

declare(strict_types=1);
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Guard\LedgerEventKind;
use Lenorix\DatadisClient\Http\ApiCaller;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Lenorix\DatadisClient\Tariff\AccessFareParser;
use Lenorix\DatadisClient\Time\DatadisDate;
use Lenorix\DatadisClient\Time\MonthPlanner;
use Lenorix\DatadisClient\Time\TimeInstant;
use Lenorix\DatadisClient\Time\WallClock;

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed();

arch('every source file declares strict types')
    ->expect('Lenorix\DatadisClient')
    ->toUseStrictTypes();

arch('source classes are final, except the exception bases')
    ->expect('Lenorix\DatadisClient')
    ->classes()
    ->toBeFinal()
    ->ignoring([DatadisException::class, InvalidRequestException::class]);

arch('every exception extends DatadisException')
    ->expect('Lenorix\DatadisClient\Exceptions')
    ->toExtend(DatadisException::class)
    ->ignoring(DatadisException::class);

arch('only the default wiring knows about Guzzle')
    ->expect('GuzzleHttp')
    ->toOnlyBeUsedIn([
        GuzzleClientFactory::class,
        ApiCaller::class,
    ]);

arch('results and values are immutable, and Data holds only results')
    ->expect(['Lenorix\DatadisClient\Data', 'Lenorix\DatadisClient\Values', 'Lenorix\DatadisClient\Time', 'Lenorix\DatadisClient\Guard\LedgerEvent', 'Lenorix\DatadisClient\Tariff', 'Lenorix\DatadisClient\ConnectionSettings'])
    ->classes()
    ->toBeReadonly()
    ->ignoring([
        WallClock::class,
        DatadisDate::class,
        TimeInstant::class,
        MonthPlanner::class,
        // A parser of static functions, without state.
        AccessFareParser::class,
    ]);

it('keeps the values an application may store for the ledger events', function () {
    // An application may keep the kind of each event in a table: these values are part of the API.
    expect(array_map(fn (LedgerEventKind $k) => $k->value, LedgerEventKind::cases()))
        ->toBe(['claimed', 'released', 'remembered', 'refused']);
});
