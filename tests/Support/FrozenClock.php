<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** A clock that only moves when told to. */
final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now = new DateTimeImmutable('2026-01-15 12:00:00 UTC')) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }
}
