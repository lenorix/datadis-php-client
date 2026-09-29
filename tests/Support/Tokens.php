<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

/** Builds fake, unsigned JWTs for tests. They are not real credentials. */
final class Tokens
{
    /** @param array<string, mixed> $claims */
    public static function jwt(array $claims): string
    {
        $encode = static fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $encode('{"alg":"none"}').'.'.$encode(json_encode($claims, JSON_THROW_ON_ERROR)).'.signature';
    }
}
