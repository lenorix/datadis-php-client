<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * A daily call (getLatest...Of()) has no month to ask for: the supply's contract ended before the
 * current month, or starts after it. Not a failure of the sync: there is nothing new to fetch.
 * Nothing was sent.
 */
final class NothingToRefreshException extends InvalidRequestException {}
