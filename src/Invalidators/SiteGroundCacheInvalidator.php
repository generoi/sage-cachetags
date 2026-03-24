<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;

/**
 * SiteGround cache invalidator.
 *
 * Each sg_cachepress_purge_cache() call opens a synchronous Unix socket
 * connection to SiteGround's Site Tools daemon, sends a JSON purge request,
 * and blocks until a response is received. When purging runs on PHP shutdown
 * (after the response is sent to the client), the Apache worker thread remains
 * occupied for the duration of all purge calls.
 *
 * With large tag sets (e.g. archive:mfn_news mapping to ~2000 URLs), this
 * results in thousands of sequential blocking socket operations on a single
 * worker thread, which can overwhelm the Site Tools daemon and exhaust
 * Apache's worker pool.
 *
 * To prevent this, a bulk purge threshold is used: when the number of URLs
 * exceeds the threshold, a single full cache flush is performed instead.
 *
 * @see cachetags/siteground-bulk-purge-threshold filter to customize the threshold.
 */
class SiteGroundCacheInvalidator implements Invalidator
{
    /**
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        $threshold = apply_filters('cachetags/siteground-bulk-purge-threshold', 50);

        if (count($urls) > $threshold) {
            return $this->flush();
        }

        return array_reduce(
            $urls,
            fn ($result, $url) => \sg_cachepress_purge_cache($url) ? $result : false,
            true
        );
    }

    public function flush(): bool
    {
        return \sg_cachepress_purge_cache() ?: false;
    }
}
