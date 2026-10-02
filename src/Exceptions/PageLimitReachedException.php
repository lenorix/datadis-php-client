<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * A walk through every page of a public search stopped at its page limit while the last page was
 * full, so more records may remain. Thrown after every record read was yielded: continue from
 * `nextPage` with a query that starts there, or raise the limit.
 */
final class PageLimitReachedException extends DatadisException
{
    public function __construct(
        string $message,
        string $endpoint,
        public readonly int $nextPage,
        public readonly int $skippedRows,
    ) {
        parent::__construct($message, endpoint: $endpoint);
    }
}
