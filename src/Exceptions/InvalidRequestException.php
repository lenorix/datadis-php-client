<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

use Throwable;

/**
 * The arguments cannot make a valid request (an impossible date range, an unknown point type).
 * Raised before anything is sent, on purpose: Datadis counts a rejected request against the 24 hour
 * repetition window, so a request known to be wrong must never leave the machine.
 */
final class InvalidRequestException extends DatadisException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, requestSent: false, previous: $previous);
    }
}
