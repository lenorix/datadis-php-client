<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\PublicApi;

/**
 * What a walk through every page of a public search read, returned by the generator of
 * apiSearchAll() and apiSearchAutoAll() once it has yielded its last record (`getReturn()`).
 */
final readonly class PageWalk
{
    /**
     * @param  int  $pages  pages read
     * @param  int  $skippedRows  rows that could not be read and were left out, across all pages
     */
    public function __construct(
        public int $pages,
        public int $skippedRows,
    ) {}
}
