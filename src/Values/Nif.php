<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Values;

use Closure;
use InvalidArgumentException;
use SensitiveParameter;
use Stringable;

/**
 * A tax identification document (NIF, NIE or CIF) as used for the account and for `authorizedNif`.
 *
 * Datadis' tolerance for lowercase, spaces or hyphens is unknown, so the normalised value
 * (trimmed, uppercase) is what gets sent. The control character is checked by default: a mistyped
 * NIF would be sent and refused, and a refused data query still counts against the 24 hour rule.
 * Pass `checkControl: false` to take one as it is.
 *
 * It is personal data, so the value lives in a closure, which var_export cannot show, and
 * __debugInfo leaves it out of var_dump and print_r. serialize() keeps it, to store it on purpose.
 */
final readonly class Nif implements Stringable
{
    /** A DNI, an NIE, a NIF K, L or M (people without a DNI or NIE), or a CIF. */
    private const string PATTERN = '/^(?:\d{8}[A-Z]|[XYZKLM]\d{7}[A-Z]|[A-HJNP-SUVW]\d{7}[0-9A-J])$/D';

    /** The NIF and NIE letter of each remainder of the number divided by 23. */
    private const string LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    /** @var Closure(): string */
    private Closure $value;

    private function __construct(#[SensitiveParameter] string $value)
    {
        $this->value = static fn (): string => $value;
    }

    public static function fromString(#[SensitiveParameter] string $value, bool $checkControl = true): self
    {
        $normalised = self::normalise($value);

        if (preg_match(self::PATTERN, $normalised) !== 1) {
            throw new InvalidArgumentException('Not a NIF, NIE or CIF.');
        }

        if ($checkControl && ! self::hasValidControl($normalised)) {
            throw new InvalidArgumentException('The control character of the NIF, NIE or CIF does not match; check it, or pass checkControl: false.');
        }

        return new self($normalised);
    }

    public static function isValid(#[SensitiveParameter] string $value, bool $checkControl = true): bool
    {
        $normalised = self::normalise($value);

        return preg_match(self::PATTERN, $normalised) === 1 && (! $checkControl || self::hasValidControl($normalised));
    }

    public function value(): string
    {
        return ($this->value)();
    }

    public function equals(self $other): bool
    {
        return $this->value() === $other->value();
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
        $value = self::fromString(is_string($data['value'] ?? null) ? $data['value'] : '', false)->value();
        $this->value = static fn (): string => $value;
    }

    /** Takes a value that already has the shape of a NIF, NIE or CIF. */
    private static function hasValidControl(#[SensitiveParameter] string $value): bool
    {
        $first = $value[0];

        // NIF and NIE: the number (an NIE's X, Y, Z standing for 0, 1, 2) modulo 23 picks the letter.
        if (ctype_digit($first) || str_contains('XYZ', $first)) {
            $number = (int) strtr(substr($value, 0, 8), ['X' => '0', 'Y' => '1', 'Z' => '2']);

            return $value[8] === self::LETTERS[$number % 23];
        }

        // NIF K, L and M (RD 1065/2007; AEAT guide to the NIF): the same letter algorithm as a DNI, on the seven digits.
        if (str_contains('KLM', $first)) {
            return $value[8] === self::LETTERS[(int) substr($value, 1, 7) % 23];
        }

        // CIF: digits in odd places are doubled and their digits added; the control is a digit or its letter.
        $sum = 0;

        foreach (str_split(substr($value, 1, 7)) as $i => $digit) {
            $sum += $i % 2 === 0 ? array_sum(str_split((string) ((int) $digit * 2))) : (int) $digit;
        }

        $control = (10 - $sum % 10) % 10;
        $digit = $value[8] === (string) $control;
        $letter = $value[8] === 'JABCDEFGHI'[$control];

        // The first letter decides the kind of control where every source agrees: always a digit
        // for companies and communities (A, B, E, H), always a letter for public bodies (P, Q, S).
        return match (true) {
            str_contains('ABEH', $first) => $digit,
            str_contains('PQS', $first) => $letter,
            default => $digit || $letter,
        };
    }

    private static function normalise(#[SensitiveParameter] string $value): string
    {
        return strtoupper(trim($value));
    }
}
