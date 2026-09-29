<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Values;

use InvalidArgumentException;
use Stringable;

/**
 * A tax identification document (NIF, NIE or CIF) as used for the account and for `authorizedNif`.
 *
 * Datadis' tolerance for lowercase, spaces or hyphens is unknown, so the normalised value
 * (trimmed, uppercase) is what gets sent.
 */
final readonly class Nif implements Stringable
{
    private const string PATTERN = '/^(?:\d{8}[A-Z]|[XYZ]\d{7}[A-Z]|[A-HJ-NP-SUVW]\d{7}[0-9A-J])$/D';

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $normalised = self::normalise($value);

        if (preg_match(self::PATTERN, $normalised) !== 1) {
            throw new InvalidArgumentException('Not a NIF, NIE or CIF.');
        }

        return new self($normalised);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, self::normalise($value)) === 1;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function sameAs(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function normalise(string $value): string
    {
        return strtoupper(trim($value));
    }
}
