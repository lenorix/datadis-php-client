<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tariff;

use Brick\Math\BigDecimal;
use Lenorix\DatadisClient\Support\Decimal;

/**
 * Access tariffs (peajes de acceso) of Circular CNMC 3/2020.
 *
 * 2.0TD: low voltage up to 15 kW, 3 energy and 2 power periods, no ordering rule for the powers.
 * 3.0TD: low voltage above 15 kW. 6.1TD to 6.4TD: high voltage bands from 1, 30, 72.5 and 145 kV.
 * All of them have 6 energy and 6 power periods with non-decreasing contracted power (P1 <= ... <= P6).
 */
enum AccessTariff: string
{
    case T20TD = '2.0TD';
    case T30TD = '3.0TD';
    case T61TD = '6.1TD';
    case T62TD = '6.2TD';
    case T63TD = '6.3TD';
    case T64TD = '6.4TD';

    public function energyPeriods(): int
    {
        return $this === self::T20TD ? 3 : 6;
    }

    public function powerPeriods(): int
    {
        return $this === self::T20TD ? 2 : 6;
    }

    public function requiresNonDecreasingPower(): bool
    {
        return $this !== self::T20TD;
    }

    /**
     * Whether a list of contracted powers fits the tariff: one positive value per power period and,
     * where required, non-decreasing. Real contracts have been seen breaking the ordering rule, so
     * this is a check for the caller to use, never enforced when reading Datadis.
     *
     * @param  array<string|null>  $powers
     */
    public function acceptsContractedPower(array $powers): bool
    {
        if (count($powers) !== $this->powerPeriods()) {
            return false;
        }

        $previous = null;

        foreach ($powers as $power) {
            if ($power === null || ! Decimal::isNumeric($power)) {
                return false;
            }

            $value = BigDecimal::of(Decimal::of($power, 6));

            if (! $value->isPositive()) {
                return false;
            }

            if ($this->requiresNonDecreasingPower() && $previous !== null && $value->isLessThan($previous)) {
                return false;
            }

            $previous = $value;
        }

        return true;
    }
}
