<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * The same query was already made in the last 24 hours: Datadis answered HTTP 429, or the client's
 * guard refused it locally (no HTTP status, `requestSent = false`). Never retry it.
 */
final class RepetitionWindowException extends DatadisException {}
