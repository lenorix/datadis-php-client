<?php

declare(strict_types=1);
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;

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
    ->ignoring([
        DatadisException::class,
        ServiceUnavailableException::class,
    ]);

arch('every exception extends DatadisException')
    ->expect('Lenorix\DatadisClient\Exceptions')
    ->toExtend(DatadisException::class)
    ->ignoring(DatadisException::class);
