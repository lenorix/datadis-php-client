<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use Eris\Generator;
use Eris\Generators;

/**
 * Generators for property-based tests. Eris' regex generator needs an optional
 * dependency, so the identifier-shaped strings are composed from plain generators.
 */
final class Gen
{
    public static function digits(int $length): Generator
    {
        return Generators::map(
            fn (array $digits): string => implode('', $digits),
            Generators::vector($length, Generators::choose(0, 9)),
        );
    }

    /** ASCII letters of random case. */
    public static function letters(int $length): Generator
    {
        return Generators::map(
            fn (array $chars): string => implode('', $chars),
            Generators::vector($length, Generators::elements(...str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'))),
        );
    }
}
