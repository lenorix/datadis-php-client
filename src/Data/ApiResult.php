<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

/**
 * The records of a list endpoint together with what else the answer said.
 *
 * An empty list is a normal success ("nothing published for that period") and is never zero
 * consumption. An empty list with distributor errors means a distributor failed instead.
 *
 * @template T
 */
final readonly class ApiResult
{
    /**
     * @param  list<T>  $records
     * @param  list<DistributorError>  $distributorErrors  partial failures reported inside a 200 (v2 only)
     * @param  int  $skippedRows  rows that could not be used and were left out
     * @param  array<array-key, mixed>  $raw  the decoded payload as received
     */
    public function __construct(
        public array $records,
        public array $distributorErrors = [],
        public int $skippedRows = 0,
        public array $raw = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function hasDistributorErrors(): bool
    {
        return $this->distributorErrors !== [];
    }

    public function isEmptyBecauseOfErrors(): bool
    {
        return $this->isEmpty() && $this->hasDistributorErrors();
    }
}
