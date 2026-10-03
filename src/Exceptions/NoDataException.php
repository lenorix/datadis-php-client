<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * Datadis has no data for the request: 404, 204 or an empty body. Treat it like an empty result; a
 * month that is not published yet usually comes back as an empty list instead. It is never zero
 * consumption. The supplies and distributors lists read Datadis's 404 "No supplies" as an empty
 * list; any other 404 there is an UninterpretableResponseException, never this one.
 */
final class NoDataException extends DatadisException {}
