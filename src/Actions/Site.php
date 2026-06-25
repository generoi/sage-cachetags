<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tag;
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
     * @param  array<string|Tag>  $tags
     * @return Tag[]
     */
    public function addSitePrefix(array $tags): array
    {
        $siteId = get_current_blog_id();

        return array_map(
            function ($tag) use ($siteId) {
                $parsed = Tag::from($tag);

                // Leave this site's own bare tag unscoped; scope everything else
                // (incl. a custom site:foo or another site's tag flowing through).
                return $parsed->type === 'site' && $parsed->id === $siteId && $parsed->scopes === []
                    ? $parsed
                    : $parsed->scope('site', $siteId);
            },
            $tags
        );
    }

    public function addSiteTag(): void
    {
        $this->cacheTags->add(SiteTags::sites());
    }
}
