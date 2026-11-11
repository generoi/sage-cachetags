<?php

namespace Genero\Sage\CacheTags\Invalidators;

/**
 * Fastly soft purge: the object is marked stale and served while Fastly
 * revalidates. A purge that includes a hard-purge tag (by default `nonce`) is
 * sent without the soft header: a soft purge would keep serving the old page,
 * and its expired nonce, to the first visitor after it, which on a quiet page
 * can be days later.
 *
 * @see cachetags/hard-purge-tags filter: add a site's own tags, or return []
 *      to soft-purge everything. The Site action's `site:<id>:` prefix is
 *      ignored when matching.
 */
class FastlySoftCacheInvalidator extends FastlyCacheInvalidator
{
    /** Set while a purge that must not serve stale is being sent. */
    protected bool $hard = false;

    public function clear(array $urls, array $tags): bool
    {
        $this->hard = $this->needsHardPurge($tags);

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

    /**
     * @param  string[]  $tags
     */
    protected function needsHardPurge(array $tags): bool
    {
        $hard = (array) apply_filters('cachetags/hard-purge-tags', ['nonce']);

        foreach ($tags as $tag) {
            if (in_array(preg_replace('/^site:\d+:/', '', (string) $tag), $hard, true)) {
                return true;
            }
        }

        return false;
    }
}
