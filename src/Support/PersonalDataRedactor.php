<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Support;

use SensitiveParameter;

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
        // An email address, which an error message may echo.
        '/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i',
        // A JWT (base64url JSON always starts with "eyJ"): a credential that an error body could echo.
        '/eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/',
        // No boundaries on purpose: over-redacting a CUPS glued to other text is the safe side.
        '/ES\d{16}[A-Z]{2}(?:\d[A-Z])?/i',
        // NIF, NIE and CIF may be written with a dash or any whitespace before the letter, or glued
        // to a label such as "NIF00000000A" or "CIFA0000000A". Only digits around them are ruled out.
        '/(?<!\d)\d{8}(?:\s+|-)?[A-Z](?![0-9])/i',
        '/(?<!\d)[XYZKLM](?:\s+|-)?\d{7}(?:\s+|-)?[A-Z](?![0-9])/i',
        '/(?<!\d)[A-HJ-NP-SUVW](?:\s+|-)?\d{7}(?:\s+|-)?[0-9A-J](?![0-9])/i',
    ];

    public static function redact(#[SensitiveParameter] string $text): string
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
    public static function excerpt(#[SensitiveParameter] string $text, int $max = 300): string
    {
        // Whitespace is collapsed first: collapsing after redacting could join the parts of an identifier again.
        $clean = self::redact(trim(preg_replace('/\s+/u', ' ', mb_scrub($text)) ?? ''));

        return mb_substr($clean, 0, max(0, $max));
    }

    /**
     * The text without the given secrets (a password, a token) in any form a server or an HTTP
     * client may print them: as they are; form encoded (`+` for a space), percent encoded, and as
     * Java's URLEncoder writes them; escaped in JSON (with or without escaped slashes and non-ASCII
     * characters); escaped in HTML with named or numeric entities; and as Latin-1 bytes, a Java
     * servlet's default. Case is ignored, since percent encoding may use either case; removing
     * too much is the safe side. A secret cannot be recognised by shape, hence the exact forms.
     *
     * @param  list<string>  $secrets
     */
    public static function withoutSecrets(#[SensitiveParameter] string $text, #[SensitiveParameter] array $secrets): string
    {
        $forms = [];

        foreach ($secrets as $secret) {
            $html = htmlspecialchars($secret, ENT_QUOTES | ENT_HTML401);
            $forms = [
                ...$forms,
                $secret,
                urlencode($secret),
                rawurlencode($secret),
                str_replace('%2A', '*', urlencode($secret)),
                htmlspecialchars($secret, ENT_QUOTES | ENT_HTML5),
                $html,
                str_replace('&#039;', '&#39;', $html),
                htmlentities($secret, ENT_QUOTES | ENT_HTML401),
                htmlentities($secret, ENT_QUOTES | ENT_HTML5),
                mb_encode_numericentity($secret, [0x0, 0x10FFFF, 0, 0x10FFFF], 'UTF-8'),
                mb_encode_numericentity($secret, [0x0, 0x10FFFF, 0, 0x10FFFF], 'UTF-8', true),
                mb_convert_encoding($secret, 'ISO-8859-1', 'UTF-8'),
            ];

            foreach ([0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
                $json = json_encode($secret, $flags);
                $forms[] = $json === false ? $secret : substr($json, 1, -1);
            }
        }

        // Longest first, so a form that contains another is removed whole.
        $forms = array_values(array_unique(array_filter($forms, static fn (string $form): bool => $form !== '')));
        usort($forms, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return str_ireplace($forms, self::PLACEHOLDER, $text);
    }
}
