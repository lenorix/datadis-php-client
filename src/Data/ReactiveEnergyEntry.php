<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use Lenorix\DatadisClient\Decoding\Fields;
use SensitiveParameter;

/** Reactive energy of one date, per power period (`energy_p1`..`energy_p6`), decimal strings with scale 3. */
final readonly class ReactiveEnergyEntry
{
    /**
     * @param  array<int, string>  $periods  period number => kVArh, only the periods that had a value
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public ?string $date,
        public array $periods,
        public array $raw,
    ) {}

    /** @param array<array-key, mixed> $row */
    public static function fromRow(#[SensitiveParameter] array $row): self
    {
        $periods = [];
        foreach (range(1, 6) as $period) {
            $value = Fields::decimal($row, 3, "energy_p{$period}");

            if ($value !== null) {
                $periods[$period] = $value;
            }
        }

        return new self(Fields::text($row, 'date'), $periods, $row);
    }
}
