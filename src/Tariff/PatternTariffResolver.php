<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tariff;

use InvalidArgumentException;
use Lenorix\DatadisClient\Data\ContractDetail;

/**
 * Your own reading of the tariff: regular expressions tried in order on the contract's text
 * fields, the first match winning. For the wordings of the companies you deal with, kept in your
 * configuration; chain it before or after the standard one with ChainTariffResolver.
 *
 *     new PatternTariffResolver(['/peaje\s*2\.?0/i' => AccessTariff::T20TD, '/^62$/' => AccessTariff::T62TD])
 */
final readonly class PatternTariffResolver implements TariffResolver
{
    /**
     * @param  array<string, AccessTariff>  $patterns  regular expression => tariff, in order
     * @param  list<'accessFare'|'codeFare'|'timeDiscrimination'|'tension'>  $fields  the fields to try them on, in order
     *
     * @throws InvalidArgumentException when a pattern is not a valid regular expression
     */
    public function __construct(
        private array $patterns,
        private array $fields = ['accessFare', 'codeFare'],
    ) {
        foreach (array_keys($patterns) as $pattern) {
            // preg_match warns about a broken pattern before it returns false: the warning is
            // turned into this exception instead.
            set_error_handler(static fn (): bool => true);

            try {
                $valid = preg_match($pattern, '') !== false;
            } finally {
                restore_error_handler();
            }

            if (! $valid) {
                throw new InvalidArgumentException("Not a valid regular expression: {$pattern}");
            }
        }
    }

    public function resolve(ContractDetail $contract): ?AccessTariff
    {
        foreach ($this->fields as $field) {
            $text = $contract->{$field};

            if (! is_string($text) || $text === '') {
                continue;
            }

            foreach ($this->patterns as $pattern => $tariff) {
                if (preg_match($pattern, $text) === 1) {
                    return $tariff;
                }
            }
        }

        return null;
    }
}
