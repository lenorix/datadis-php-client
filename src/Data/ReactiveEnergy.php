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
     * @return self|null null when the object is empty
     */
    public static function fromRow(#[SensitiveParameter] array $row): ?self
    {
        if ($row === []) {
            return null;
        }

        $energy = [];
        $items = $row['energy'] ?? null;

        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $energy[] = ReactiveEnergyEntry::fromRow($item);
                }
            }
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
