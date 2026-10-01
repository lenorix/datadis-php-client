<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

/**
 * Leaves the personal fields of a record (CUPS, addresses, names, documents, the answer as received)
 * out of var_dump, print_r and the dumpers that follow __debugInfo, such as Symfony's and Laravel's
 * dump(). The values are still read from the properties; var_export shows them, as for any public
 * property.
 *
 * The using class lists its personal fields in `PERSONAL_FIELDS`. A null field stays null, so a dump
 * still tells which fields came.
 *
 * @internal
 */
trait HidesPersonalData
{
    /** @return array<mixed> */
    public function __debugInfo(): array
    {
        $fields = get_object_vars($this);

        foreach (self::PERSONAL_FIELDS as $name) {
            if ($fields[$name] !== null && $fields[$name] !== []) {
                $fields[$name] = '[hidden]';
            }
        }

        return $fields;
    }
}
