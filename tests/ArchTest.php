<?php

declare(strict_types=1);
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Lenorix\DatadisClient\PublicApi\PublicApi;
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

arch('source classes are final, except the exception base')
    ->expect('Lenorix\DatadisClient')
    ->classes()
    ->toBeFinal()
    ->ignoring(DatadisException::class);

arch('every exception extends DatadisException')
    ->expect('Lenorix\DatadisClient\Exceptions')
    ->toExtend(DatadisException::class)
    ->ignoring(DatadisException::class);

arch('only the default wiring knows about Guzzle')
    ->expect('GuzzleHttp')
    ->toOnlyBeUsedIn([
        GuzzleClientFactory::class,
        DatadisClient::class,
        PublicApi::class,
    ]);

arch('results and values are immutable, and Data holds only results')
    ->expect(['Lenorix\DatadisClient\Data', 'Lenorix\DatadisClient\Values', 'Lenorix\DatadisClient\Time'])
    ->classes()
    ->toBeReadonly()
    ->ignoring([
        WallClock::class,
        DatadisDate::class,
        TimeInstant::class,
        MonthPlanner::class,
    ]);
