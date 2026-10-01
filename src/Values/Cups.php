<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Values;

use Closure;
use InvalidArgumentException;
use SensitiveParameter;
use Stringable;

/**
 * A CUPS supply point code: `ES` + 16 digits + 2 letters, optionally followed by a 2 character
 * frontier suffix (a digit and a letter, such as `0F`).
 *
 * Retailers sometimes print extra characters on invoices. The value shown in the Datadis portal is
 * the reference, and a malformed value is refused locally because the API burns its 24 hour
 * repetition window on rejected requests.
 *
 * It identifies a home, so the value lives in a closure, which var_export cannot show, and
 * __debugInfo leaves it out of var_dump and print_r. serialize() keeps it, to store it on purpose.
 */
final readonly class Cups implements Stringable
{
    private const string PATTERN = '/^ES\d{16}[A-Z]{2}(?:\d[A-Z])?$/D';

    /** @var Closure(): string */
    private Closure $value;

    private function __construct(#[SensitiveParameter] string $value)
    {
        $this->value = static fn (): string => $value;
    }

    public static function fromString(#[SensitiveParameter] string $value): self
    {
        $normalised = self::normalise($value);

        if (preg_match(self::PATTERN, $normalised) !== 1) {
            throw new InvalidArgumentException('Not a CUPS: expected ES + 16 digits + 2 letters, optionally + a digit and a letter.');
        }

        return new self($normalised);
    }

    public static function isValid(#[SensitiveParameter] string $value): bool
    {
        return preg_match(self::PATTERN, self::normalise($value)) === 1;
    }

    /** The full normalised value (trimmed, uppercase), 20 or 22 characters. */
    public function value(): string
    {
        return ($this->value)();
    }

    /** The first 20 characters. Distributors inconsistently include the frontier suffix, so match on this. */
    public function base(): string
    {
        return substr($this->value(), 0, 20);
    }

    public function matches(self $other): bool
    {
        return $this->base() === $other->base();
    }

    public function __toString(): string
    {
        return $this->value();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[hidden]'];
    }

    /** @return array{value: string} */
    public function __serialize(): array
    {
        return ['value' => $this->value()];
    }

    /** @param  array<mixed>  $data */
    public function __unserialize(array $data): void
    {
        // Checked again: a stored value may have been tampered with or written by hand.
        $value = self::fromString(is_string($data['value'] ?? null) ? $data['value'] : '')->value();
        $this->value = static fn (): string => $value;
    }

    private static function normalise(#[SensitiveParameter] string $value): string
    {
        return strtoupper(trim($value));
    }
}
