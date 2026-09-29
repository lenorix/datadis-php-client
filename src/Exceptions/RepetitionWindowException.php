<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * HTTP 429: the same query was already made in the last 24 hours. Never retry it.
 */
final class RepetitionWindowException extends DatadisException {}
