<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Support;

/**
 * Flattens free text before it is matched: accents removed, lowercase, whitespace collapsed.
 * Anything without an ASCII form (such as `€` or `≤`) is dropped, so callers that care about a
 * symbol must replace it first.
 *
 * The transliteration table is explicit on purpose: iconv and intl give different results on
 * different systems.
 *
 * @internal
 */
final class TextNormalizer
{
    private const array ASCII = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ª' => 'a', 'º' => 'o',
        'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Ã' => 'a', 'Å' => 'a',
        'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Ö' => 'o', 'Õ' => 'o',
        'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'Ñ' => 'n', 'Ç' => 'c',
    ];

    public static function normalize(string $text): string
    {
        // Byte-wise on purpose: after the table, any byte outside ASCII (including broken UTF-8) goes.
        $text = strtr($text, self::ASCII);
        $text = preg_replace('/[^\x00-\x7F]/', '', $text) ?? '';
        $text = strtolower($text);

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
