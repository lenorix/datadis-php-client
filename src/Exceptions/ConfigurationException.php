<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

use Throwable;

/** Missing or invalid credentials or base URL. Raised before any HTTP call, so nothing was sent. */
final class ConfigurationException extends DatadisException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, requestSent: false, previous: $previous);
    }
}
