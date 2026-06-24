<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\SiteTags;

class Site implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_filter(CacheTags::FILTER_TAGS, [$this, 'addSitePrefix']);
        \add_action('template_redirect', [$this, 'addSiteTag']);
        \add_action('rest_api_init', [$this, 'addSiteTag']);
    }

    /**
     * @param  string[]  $tags
     * @return string[]
     */
    public function addSitePrefix(array $tags): array
    {
        $siteId = get_current_blog_id();
        $siteTags = SiteTags::sites();

        return array_map(
            function (string $tag) use ($siteId, $siteTags) {
                return in_array($tag, $siteTags)
                    ? $tag
                    : sprintf('site:%d:%s', $siteId, $tag);
            },
            $tags
        );
    }

    public function addSiteTag(): void
    {
        $this->cacheTags->add([
            ...SiteTags::sites(),
        ]);
    }
}
