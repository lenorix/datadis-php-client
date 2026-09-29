<?php

declare(strict_types=1);

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed();

arch('every source file declares strict types')
    ->expect('Lenorix\DatadisClient')
    ->toUseStrictTypes();

arch('source classes are final')
    ->expect('Lenorix\DatadisClient')
    ->classes()
    ->toBeFinal();
