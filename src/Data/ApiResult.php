<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The records of a list endpoint together with what else the answer said.
 *
 * An empty list is a normal success ("nothing published for that period") and is never zero
 * consumption. An empty list with distributor errors means a distributor failed instead.
 *
 * Iterating or counting a result iterates or counts its records.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class ApiResult implements Countable, IteratorAggregate
{
    use HidesPersonalData;

    /** Shown as [hidden] in dumps: see HidesPersonalData. */
    private const array PERSONAL_FIELDS = ['raw'];

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

    /** Nothing came back and a distributor failed. A distributor that only says it has no data for the period is not a failure. */
    public function isEmptyBecauseOfErrors(): bool
    {
        if (! $this->isEmpty()) {
            return false;
        }

        foreach ($this->distributorErrors as $error) {
            if (! $error->isNoData()) {
                return true;
            }
        }

        return false;
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->records);
    }
}
