<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

use Throwable;

/** The operation does not exist in the chosen API version (for example reactive data in v1). Nothing was sent. */
final class UnsupportedOperationException extends DatadisException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, requestSent: false, previous: $previous);
    }
}
