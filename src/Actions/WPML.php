<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;

/**
 * Keep WPML's `lang` query var in the cache key, so query-string language
 * variants are cached separately. (Directory/domain language modes use a
 * distinct path/host, so they key correctly without help.)
 *
 * The Polylang equivalent lives in the Polylang action; enable whichever
 * multilingual plugin the site uses.
 */
class WPML implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        if (! defined('ICL_SITEPRESS_VERSION')) {
            return;
        }

        \add_filter(Bootstrap::FILTER_ALLOWED_PARAMS, [$this, 'allowLanguageParam']);
    }

    /**
     * @param  string[]  $params
     * @return string[]
     */
    public function allowLanguageParam(array $params): array
    {
        return [...$params, 'lang'];
    }
}
