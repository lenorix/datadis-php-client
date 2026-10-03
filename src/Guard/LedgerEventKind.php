<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

enum LedgerEventKind: string
{
    /** The query was recorded as attempted, just before it is sent. */
    case Claimed = 'claimed';

    /** The record was dropped: the request provably never left, so the query is free again. */
    case Released = 'released';

    /** An attempt made earlier was recorded with its own time (rememberAt()). */
    case Remembered = 'remembered';

    /**
     * The query was not sent: an attempt within the window holds it. The call fails with a
     * RepetitionWindowException, and the event says so for a history that audits refusals too.
     */
    case Refused = 'refused';
}
