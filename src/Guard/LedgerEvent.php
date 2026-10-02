<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

use DateTimeImmutable;

/**
 * Something the ledger did with a guarded query, for an application that keeps a history of what
 * it sent. It never carries the CUPS or a NIF: `key` is the ledger's opaque key, the same for the
 * same query, and `endpoint` the endpoint as it appears in exceptions.
 */
final readonly class LedgerEvent
{
    public function __construct(
        public LedgerEventKind $kind,
        public string $key,
        public DateTimeImmutable $at,
        public ?string $endpoint = null,
    ) {}
}
