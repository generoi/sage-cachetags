<?php

namespace Genero\Sage\CacheTags\Concerns;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Util;

/**
 * Save the response's tags at shutdown, for responses that print no wp_footer
 * (where Bootstrap saves a page's). Bind before shutdown's output-buffer flush
 * at priority 1, where page caches decide whether to keep the response. The
 * guards match Bootstrap::saveCacheTags().
 *
 * @property CacheTags $cacheTags
 */
trait SavesAtShutdown
{
    protected function saveAtShutdown(): void
    {
        if (did_action('wp_footer')) {
            return;
        }
        if (! Util::isCacheableRequest()) {
            // As Bootstrap::saveCacheTags(): make the veto actionable for page
            // caches, which would otherwise keep a response nothing can purge.
            if (! defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }

            return;
        }
        // do_feed() wp_die()s an unknown ?feed= with a 404 without set_404().
        if (is_404() || http_response_code() >= 400 || Util::isUnstoredQueryVariant()) {
            return;
        }

        $this->cacheTags->save(Util::currentUrl());
    }
}
