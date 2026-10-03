<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

use Lenorix\DatadisClient\Time\Month;

/**
 * A month asked for is outside what Datadis serves: older than the last 24 months, the boundary one
 * included, or in the future. Nothing was sent.
 */
final class OutOfServedRangeException extends InvalidRequestException
{
    public function __construct(string $message, public readonly Month $month)
    {
        parent::__construct($message);
    }
}
