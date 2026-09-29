<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Calendar;

use DateTimeZone;

/**
 * The territories whose regulated schedules or civil time differ.
 *
 * Datadis sends civil time without an offset: the Canary Islands are one hour behind the rest.
 */
enum Territory: string
{
    case Peninsula = 'peninsula';
    case Baleares = 'baleares';
    case Canarias = 'canarias';
    case Ceuta = 'ceuta';
    case Melilla = 'melilla';

    /**
     * The territory of a Spanish postal code, from the province in its first two digits.
     * Null when the value is not a postal code of an assigned province (01 to 52); null is not
     * "Peninsula".
     */
    public static function fromPostalCode(?string $postalCode): ?self
    {
        $postalCode = $postalCode === null ? '' : trim($postalCode);

        if (preg_match('/^(\d{2})\d{3}$/D', $postalCode, $m) !== 1 || $m[1] < '01' || $m[1] > '52') {
            return null;
        }

        return match ($m[1]) {
            '07' => self::Baleares,
            '35', '38' => self::Canarias,
            '51' => self::Ceuta,
            '52' => self::Melilla,
            default => self::Peninsula,
        };
    }

    public function timeZone(): DateTimeZone
    {
        return new DateTimeZone($this === self::Canarias ? 'Atlantic/Canary' : 'Europe/Madrid');
    }
}
