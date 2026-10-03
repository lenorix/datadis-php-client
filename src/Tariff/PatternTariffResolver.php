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
    /** The contract's text fields a pattern can be tried on. */
    public const array FIELDS = ['accessFare', 'codeFare', 'timeDiscrimination', 'tension'];

    /** @var array<string, AccessTariff> */
    private array $patterns;

    /** @var list<'accessFare'|'codeFare'|'timeDiscrimination'|'tension'> */
    private array $fields;

    /**
     * @param  array<string, mixed>  $patterns  regular expression => tariff (or its name, `3.0TD`, as a configuration file has it), in order
     * @param  list<string>  $fields  the fields to try them on, in order: any of FIELDS
     *
     * @throws InvalidArgumentException when a pattern does not compile, a tariff or a field is unknown
     */
    public function __construct(array $patterns, array $fields = ['accessFare', 'codeFare'])
    {
        $checked = [];

        foreach ($patterns as $pattern => $tariff) {
            $pattern = (string) $pattern;
            self::compiles($pattern) || throw new InvalidArgumentException("Not a valid regular expression: {$pattern}");
            $checked[$pattern] = $tariff instanceof AccessTariff ? $tariff
                : ((is_string($tariff) ? AccessTariff::tryFrom($tariff) : null) ?? throw new InvalidArgumentException('Not an access tariff: '.(is_string($tariff) ? $tariff : get_debug_type($tariff))));
        }

        foreach ($fields as $field) {
            in_array($field, self::FIELDS, true) || throw new InvalidArgumentException("Not a text field of a contract: {$field}; use one of ".implode(', ', self::FIELDS).'.');
        }

        $this->patterns = $checked;
        /** @var list<'accessFare'|'codeFare'|'timeDiscrimination'|'tension'> $fields */
        $this->fields = $fields;
    }

    /**
     * @throws InvalidArgumentException when a pattern fails on a text (too much backtracking, an
     *                                  invalid UTF-8 text for a /u pattern): a silent null would hide it
     */
    public function resolve(ContractDetail $contract): ?AccessTariff
    {
        foreach ($this->fields as $field) {
            $text = $contract->{$field};

            if ($text === null || $text === '') {
                continue;
            }

            foreach ($this->patterns as $pattern => $tariff) {
                $matched = preg_match($pattern, $text);

                if ($matched === false) {
                    throw new InvalidArgumentException("The pattern {$pattern} failed on the {$field} of a contract: ".preg_last_error_msg().'.');
                }

                if ($matched === 1) {
                    return $tariff;
                }
            }
        }

        return null;
    }

    /** preg_match warns about a broken pattern before it returns false: the warning is not let out. */
    private static function compiles(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
