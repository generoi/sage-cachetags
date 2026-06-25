<?php

namespace Genero\Sage\CacheTags\Contracts;

/**
 * A store that can be inspected for diagnostics (row counts, fan-out, the tags
 * mapped to a URL). Optional — commands degrade gracefully when the active store
 * doesn't implement it.
 */
interface InspectableStore
{
    /**
     * Total rows, distinct tags, and distinct URLs.
     *
     * @return array{rows: int, tags: int, urls: int}
     */
    public function stats(): array;

    /**
     * Tags with the most URLs (the coarse tags a single change purges widest).
     *
     * @return array<array{tag: string, urls: int}>
     */
    public function topTags(int $limit): array;

    /**
     * The tags a URL is stored under.
     *
     * @return string[]
     */
    public function tagsForUrl(string $url): array;
}
