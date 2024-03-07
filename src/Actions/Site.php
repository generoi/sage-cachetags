<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\SiteTags;

class Site implements Action
{
    protected CacheTags $cacheTags;

    public function __construct(CacheTags $cacheTags)
    {
        $this->cacheTags = $cacheTags;
    }

    public function bind(): void
    {
        \add_filter(CacheTags::FILTER_TAGS, [$this, 'addSitePrefix']);
        \add_action('template_redirect', [$this, 'addSiteTag']);
        \add_action('rest_api_init', [$this, 'addSiteTag']);
    }

    public function addSitePrefix(array $tags): array
    {
        $siteId = get_current_blog_id();
        $siteTags = SiteTags::sites();

        return collect($tags)
            ->map(function (string $tag) use ($siteId, $siteTags) {
                if (in_array($tag, $siteTags)) {
                    return $tag;
                }
                return sprintf('site:%d:%s', $siteId, $tag);
            })
            ->all();
    }

    public function addSiteTag()
    {
        $this->cacheTags->add([
            ...SiteTags::sites()
        ]);
    }
}
