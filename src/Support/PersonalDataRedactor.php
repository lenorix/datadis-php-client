<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Support;

/**
 * Removes personal identifiers from text before it reaches an exception message or a log.
 *
 * Datadis echoes rejected parameters (CUPS, NIF) in its error bodies, and it may echo identifiers
 * that were not sent, so redaction is by SHAPE and never by comparison with the values we sent.
 */
final class PersonalDataRedactor
{
    public const string PLACEHOLDER = '[redacted]';

    private const array PATTERNS = [
        // A JWT (base64url JSON always starts with "eyJ"): a credential that an error body could echo.
        '/eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/',
        // No boundaries on purpose: over-redacting a CUPS glued to other text is the safe side.
        '/ES\d{16}[A-Z]{2}(?:\d[A-Z])?/i',
        '/(?<![A-Z0-9])\d{8}[A-Z](?![A-Z0-9])/i',
        '/(?<![A-Z0-9])[XYZ]\d{7}[A-Z](?![A-Z0-9])/i',
        '/(?<![A-Z0-9])[A-HJ-NP-SUVW]\d{7}[0-9A-J](?![A-Z0-9])/i',
    ];

    public static function redact(string $text): string
    {
        // Replacing one identifier can remove the neighbour that blocked another match, so repeat until stable.
        for ($pass = 0; $pass < 5; $pass++) {
            $before = $text;

            foreach (self::PATTERNS as $pattern) {
                $replaced = preg_replace($pattern, self::PLACEHOLDER, $text);

                if ($replaced === null) {
                    return self::PLACEHOLDER;
                }

                $text = $replaced;
            }

            if ($text === $before) {
                break;
            }
        }

        return $text;
    }

    /** A redacted, single-line, valid UTF-8 excerpt of at most $max characters. */
    public static function excerpt(string $text, int $max = 300): string
    {
        $clean = self::redact(mb_scrub($text));
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        return mb_substr($clean, 0, max(0, $max));
    }
}
