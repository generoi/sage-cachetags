<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Util;

/**
 * Fastly soft purge: the object is marked stale and served while Fastly
 * revalidates. A purge that includes a hard-purge tag (by default `nonce`, see
 * Util::needsHardPurge()) is sent without the soft header, so no one is served
 * the stale page.
 */
class FastlySoftCacheInvalidator extends FastlyCacheInvalidator
{
    /** Set while a purge that must not serve stale is being sent. */
    protected bool $hard = false;

    public function clear(array $urls, array $tags): bool
    {
        $this->hard = Util::needsHardPurge($tags);

        try {
            return parent::clear($urls, $tags);
        } finally {
            $this->hard = false;
        }
    }

    protected function buildRequest($payload = null): array
    {
        $args = parent::buildRequest($payload);

        if (! $this->hard) {
            $args['headers']['fastly-soft-purge'] = '1';
        }

        return $args;
    }
}
