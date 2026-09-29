<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * Datadis has no data for the request: 404, 204 or an empty body. This is the normal answer for a
 * month that is not published yet. It is never zero consumption.
 */
final class NoDataException extends DatadisException {}
