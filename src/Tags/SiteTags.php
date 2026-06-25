<?php

namespace Genero\Sage\CacheTags\Tags;

use Genero\Sage\CacheTags\Tag;
use WP_Site;

class SiteTags
{
    /**
     * Return the cache tag(s) for the current site, given site(s), or 'any'.
     *
     * @return Tag[]
     */
    public static function sites($sites = null): array
    {
        if (is_string($sites) && $sites === 'any') {
            $sites = is_multisite() ? get_sites(['fields' => 'ids', 'number' => 0]) : [get_current_blog_id()];
        } elseif (is_numeric($sites)) {
            $sites = [$sites];
        } elseif (is_null($sites)) {
            $sites = [get_current_blog_id()];
        }

        if (is_array($sites)) {
            return array_map(
                fn ($site) => Tag::site((int) ($site instanceof WP_Site ? $site->blog_id : $site)),
                $sites
            );
        }

        return [];
    }
}
