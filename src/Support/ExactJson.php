<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Support;

use SensitiveParameter;

/**
 * Decodes JSON keeping every digit of its decimal numbers.
 *
 * json_decode() turns `0.123456789012345678901` into a float, which keeps about 15 significant
 * digits. Before decoding, each number with a fraction or an exponent is quoted, so it arrives as
 * its own text; integers are kept as integers (big ones as text, with JSON_BIGINT_AS_STRING).
 * Text inside JSON strings is never touched.
 *
 * @internal
 */
final class ExactJson
{
    /**
     * A JSON string, or a number with a fraction or an exponent. Strings are matched whole so the
     * numbers inside them are skipped. Possessive quantifiers keep long strings linear.
     */
    private const string TOKEN = '/"(?:[^"\\\\]++|\\\\.)*+"|-?(?:0|[1-9]\d*+)(?:\.\d++)?(?:[eE][+-]?\d++)?/';

    /**
     * @return mixed the decoded value, or null with json_last_error() set when the text is not JSON
     */
    public static function decode(#[SensitiveParameter] string $json): mixed
    {
        $quoted = preg_replace_callback(self::TOKEN, static function (array $match): string {
            $token = $match[0];

            // Strings and integers stay as they are. A number too long for Decimal is left to the
            // float it always was rather than turned into text no one can read.
            return $token[0] === '"' || strpbrk($token, '.eE') === false || strlen($token) > Decimal::MAX_LENGTH
                ? $token
                : '"'.$token.'"';
        }, $json);

        // Too much backtracking or broken UTF-8: decode the text as it is, as before.
        return json_decode($quoted ?? $json, true, 512, JSON_BIGINT_AS_STRING);
    }
}
