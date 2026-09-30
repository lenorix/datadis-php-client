<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Auth;

use JsonException;

/**
 * Reads the `exp` claim of a JWT without verifying it. The signature is Datadis' business: the only
 * use here is to know when to log in again, and a wrong answer just means an early or a late login.
 *
 * @internal
 */
final class JwtExpiry
{
    /** Unix timestamp of the expiry, or null when the token has no usable `exp`. */
    public static function read(string $token): ?int
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        try {
            $claims = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $exp = is_array($claims) ? ($claims['exp'] ?? null) : null;

        if (is_string($exp) && is_numeric($exp)) {
            $exp = $exp + 0;
        }

        if ((! is_int($exp) && ! is_float($exp)) || $exp <= 0 || $exp > PHP_INT_MAX / 2) {
            return null;
        }

        return (int) $exp;
    }
}
