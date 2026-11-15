<?php

namespace Genero\Sage\CacheTags\Contracts;

/**
 * A store that can list the URLs it holds, so a flush can queue every cached
 * page for warming. Optional — warming after a flush needs it, warming after
 * a purge doesn't.
 */
interface ListableStore
{
    /**
     * Distinct stored URLs, at most $limit.
     *
     * @return string[]
     */
    public function urls(int $limit): array;
}
