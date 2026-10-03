<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use Lenorix\DatadisClient\Decoding\Fields;
use SensitiveParameter;

/**
 * The reactive energy answer (v2 only). The shape comes from the official web documentation (the
 * PDF manual has no reactive section): `energy` entries with `date` (`YYYY/MM`) and `energy_p1` to
 * `energy_p6`, as numbers that may be negative, and `code` with its description, a status such as
 * `001` / `Correcto`. The real answer was captured only without data, which verified the
 * `codeDescription` key. It is usually empty for domestic supplies.
 */
final readonly class ReactiveEnergy
{
    use HidesPersonalData;

    /** Shown as [hidden] in dumps: see HidesPersonalData. */
    private const array PERSONAL_FIELDS = ['cups', 'raw'];

    /**
     * @param  list<ReactiveEnergyEntry>  $energy
     * @param  array<array-key, mixed>  $raw
     */
    private function __construct(
        public ?string $cups,
        public array $energy,
        public ?string $code,
        public ?string $codeDescription,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row  the `reactiveEnergy` object
     * @return self|null null when the object has none of its fields, or an entry that is not one
     *                   (no date and no period): an answer that cannot be read, never a record
     */
    public static function fromRow(#[SensitiveParameter] array $row): ?self
    {
        if (array_intersect(['cups', 'energy', 'code', 'codeDescription', 'code_desc'], array_keys($row)) === []) {
            return null;
        }

        $energy = [];
        $items = $row['energy'] ?? [];

        if (! is_array($items) || ! array_is_list($items)) {
            return null;
        }

        foreach ($items as $item) {
            $entry = is_array($item) ? ReactiveEnergyEntry::fromRow($item) : null;

            if ($entry === null || ($entry->date === null && $entry->periods === [])) {
                return null;
            }

            $energy[] = $entry;
        }

        return new self(
            Fields::text($row, 'cups'),
            $energy,
            Fields::text($row, 'code'),
            Fields::text($row, 'codeDescription', 'code_desc'),
            $row,
        );
    }
}
