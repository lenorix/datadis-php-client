<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient;

/**
 * The version of the private API. v2 is the default.
 *
 * v1 paths have no suffix and answer with bare JSON lists; v2 paths end in `-v2` and answer with an
 * envelope that can carry `distributorError`. Reactive data exists only in v2.
 */
enum ApiVersion: string
{
    case V1 = 'v1';
    case V2 = 'v2';

    public function suffix(): string
    {
        return $this === self::V2 ? '-v2' : '';
    }
}
