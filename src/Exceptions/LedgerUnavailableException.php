<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

use Throwable;

/**
 * The store of the repetition ledger could not be read or written, so the guarded query was not
 * sent: without a record of the attempt the guard could not protect the 24 hour window.
 */
final class LedgerUnavailableException extends DatadisException
{
    public function __construct(string $message, ?string $endpoint = null, ?Throwable $previous = null)
    {
        parent::__construct($message, endpoint: $endpoint, requestSent: false, previous: $previous);
    }
}
