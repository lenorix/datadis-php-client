<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tariff;

use Lenorix\DatadisClient\Data\ContractDetail;

/** Asks its resolvers in order and takes the first tariff one of them gives. */
final readonly class ChainTariffResolver implements TariffResolver
{
    /** @var list<TariffResolver> */
    private array $resolvers;

    public function __construct(TariffResolver ...$resolvers)
    {
        $this->resolvers = array_values($resolvers);
    }

    public function resolve(ContractDetail $contract): ?AccessTariff
    {
        foreach ($this->resolvers as $resolver) {
            $tariff = $resolver->resolve($contract);

            if ($tariff !== null) {
                return $tariff;
            }
        }

        return null;
    }
}
