<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

/**
 * The reactive energy answer (v2 only). Least verified response of the API: the field names come
 * from the manual and no real success body has been captured. It is usually empty for domestic supplies.
 */
final readonly class ReactiveEnergy
{
    /**
     * @param  list<ReactiveEnergyEntry>  $entries
     * @param  array<array-key, mixed>  $raw
     */
    private function __construct(
        public ?string $cups,
        public array $entries,
        public ?string $code,
        public ?string $codeDescription,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row  the `reactiveEnergy` object
     * @return self|null null when the object is empty
     */
    public static function fromRow(array $row): ?self
    {
        if ($row === []) {
            return null;
        }

        $entries = [];
        $energy = $row['energy'] ?? null;

        if (is_array($energy)) {
            foreach ($energy as $item) {
                if (is_array($item)) {
                    $entries[] = ReactiveEnergyEntry::fromRow($item);
                }
            }
        }

        return new self(
            Fields::text($row, 'cups'),
            $entries,
            Fields::text($row, 'code'),
            Fields::text($row, 'code_desc'),
            $row,
        );
    }
}
