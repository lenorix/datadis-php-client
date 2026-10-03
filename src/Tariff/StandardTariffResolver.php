<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tariff;

use Lenorix\DatadisClient\Data\ContractDetail;

/**
 * The package's reading of the tariff, from every signal a contract carries, never a guess:
 *
 * - `accessFare`, by shape (AccessFareParser): the voltage and power band, or an alias like `2.0TD`;
 * - `codeFare`, by a table of known codes, or an alias written in it;
 * - the number of contracted powers, which must match the tariff, and alone tells 2.0TD: it is the
 *   only tariff with two power periods.
 *
 * Signals that disagree give null. Pass `codes` to add the codes your companies use, or to replace
 * the table; for anything else, write a TariffResolver or a PatternTariffResolver and chain it.
 */
final readonly class StandardTariffResolver implements TariffResolver
{
    /**
     * `2T` comes in real answers with the 2.0TD band; `018`..`023` are the CNMC codes of the
     * tariffs of Circular 3/2020, which some distributors send.
     */
    public const array CODES = [
        '2T' => AccessTariff::T20TD,
        '018' => AccessTariff::T20TD,
        '019' => AccessTariff::T30TD,
        '020' => AccessTariff::T61TD,
        '021' => AccessTariff::T62TD,
        '022' => AccessTariff::T63TD,
        '023' => AccessTariff::T64TD,
    ];

    /** @param  array<string, AccessTariff>  $codes  codeFare (trimmed, any case) => tariff */
    public function __construct(private array $codes = self::CODES) {}

    public function resolve(ContractDetail $contract): ?AccessTariff
    {
        $powers = count($contract->contractedPowerkW);
        $signals = array_values(array_unique(array_filter([
            $contract->accessFare === null ? null : AccessFareParser::parse($contract->accessFare),
            $contract->codeFare === null ? null : $this->byCode($contract->codeFare),
        ]), SORT_REGULAR));

        if (count($signals) > 1) {
            return null;
        }

        $tariff = $signals[0] ?? ($powers === AccessTariff::T20TD->powerPeriods() ? AccessTariff::T20TD : null);

        return $tariff !== null && $tariff->powerPeriods() === $powers ? $tariff : null;
    }

    private function byCode(string $code): ?AccessTariff
    {
        $key = strtoupper(trim($code));

        foreach ($this->codes as $known => $tariff) {
            if (strtoupper(trim($known)) === $key) {
                return $tariff;
            }
        }

        // Some companies write the tariff itself as its code (`2.0TD`).
        return $key === '' ? null : AccessFareParser::parse($code);
    }
}
