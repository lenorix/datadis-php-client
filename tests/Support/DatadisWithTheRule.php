<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use DateTimeZone;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Guard\RequestLedger;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Datadis as far as the 24 hour rule goes: it refuses, and counts, an identical query within 24 hours by its own clock. */
final class DatadisWithTheRule implements ClientInterface
{
    /** @var array<string, int> when each query was last made, by Datadis's clock */
    private array $made = [];

    public int $refused = 0;

    /** @var list<string> */
    public array $sent = [];

    public function __construct(private FrozenClock $clock, private int $skewSeconds = 0) {}

    /** A client of this Datadis on the given clock: a new process each time, unless it shares a ledger. */
    public function client(?RequestLedger $ledger = null): DatadisClient
    {
        return new DatadisClient(Scenario::config(), http: $this, clock: $this->clock, ledger: $ledger);
    }

    public static function supply(string $validDateFrom = '2020/01/01', string $validDateTo = ''): Supply
    {
        return Supply::fromRow(['cups' => Scenario::CUPS, 'validDateFrom' => $validDateFrom, 'validDateTo' => $validDateTo, 'pointType' => 5, 'distributorCode' => '2'], new DateTimeZone('Europe/Madrid'))
            ?? throw new LogicException('Not a supply row.');
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $now = $this->clock->now()->getTimestamp() + $this->skewSeconds;

        if (str_contains($request->getUri()->getPath(), '/tokens/login')) {
            return Responses::text(Tokens::datadis($now));
        }

        parse_str($request->getUri()->getQuery(), $query);
        // The manual's keys: maximum power and reactive data share theirs, without the endpoint.
        $consumption = str_contains($request->getUri()->getPath(), 'consumption');
        $key = implode('|', [$consumption ? 'c' : 'p', $query['cups'], $query['startDate'], $query['endDate'], $query['measurementType'] ?? '', $query['pointType'] ?? '']);
        $this->sent[] = $key;

        if (isset($this->made[$key]) && $now - $this->made[$key] < 86400) {
            $this->made[$key] = $now;
            $this->refused++;

            return Responses::datadisError('Consulta ya realizada en las últimas 24 horas. ', 429);
        }

        $this->made[$key] = $now;

        return Responses::datadis($consumption ? '{"timeCurve":[],"distributorError":[]}' : '{"maxPower":[],"distributorError":[]}');
    }
}
