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

    /**
     * A token shaped like the real one: HS512 header, `sub`, `iat` and an `exp` 24 hours later
     * (verified). The signature is fake; the client never checks it.
     */
    public static function datadis(int $issuedAt): string
    {
        $encode = static fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $encode('{"alg":"HS512"}').'.'
            .$encode(json_encode(['sub' => 'account', 'authorities' => ['ROLE_API'], 'environment' => 'PRO', 'iat' => $issuedAt, 'exp' => $issuedAt + 86400], JSON_THROW_ON_ERROR)).'.'
            .$encode(str_repeat('s', 64));
    }
}
