<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tariff;

use InvalidArgumentException;
use Lenorix\DatadisClient\Data\ContractDetail;

/**
 * Works out the access tariff of a contract. Datadis has no tariff field: `accessFare` and
 * `codeFare` are text each company writes its own way, so how to read them is a choice an
 * application may make itself. The contract always keeps both as received.
 */
interface TariffResolver
{
    /**
     * The tariff, or null when the contract does not tell it for sure.
     *
     * @throws InvalidArgumentException when a resolver of yours cannot read a text (PatternTariffResolver:
     *                                  a pattern that fails on it), never for a contract it does not recognise
     */
    public function resolve(ContractDetail $contract): ?AccessTariff;
}
