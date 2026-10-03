<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

use DateTimeImmutable;
use Lenorix\DatadisClient\Time\Month;
use Throwable;

/**
 * The same query was already made in the last 24 hours: Datadis answered HTTP 429, or the client's
 * guard refused it locally (no HTTP status, `requestSent = false`). Never retry it.
 *
 * A local refusal says when the query was last attempted and from when the guard lets it through
 * again, so a job can skip it and a command can report it without sending anything. Datadis's
 * own 429 tells neither, so both are null then.
 *
 * Both kinds say which months the refused query asked for (`startDate`, `endDate`, both included),
 * so a caller of getLatest...Of(), whose range the client picks, knows every month it did not get.
 */
final class RepetitionWindowException extends DatadisException
{
    public function __construct(
        string $message,
        ?int $httpStatus = null,
        ?string $detail = null,
        ?string $endpoint = null,
        bool $requestSent = true,
        ?Throwable $previous = null,
        public readonly ?DateTimeImmutable $lastAttemptAt = null,
        public readonly ?DateTimeImmutable $availableAt = null,
        public readonly ?Month $startDate = null,
        public readonly ?Month $endDate = null,
    ) {
        parent::__construct($message, $httpStatus, $detail, $endpoint, $requestSent, $previous);
    }
}
