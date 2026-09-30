<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * The HTTP client failed (DNS, connection, TLS, timeout, or anything else it threw), or the answer's
 * body failed while being read.
 *
 * The outcome is unknown: a read timeout looks the same as a connection failure at the PSR-18
 * level, so the request is treated as possibly sent and guarded endpoints are not retried.
 */
final class TransportException extends DatadisException {}
