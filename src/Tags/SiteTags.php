<?php

namespace Genero\Sage\CacheTags\Tags;

use WP_Site;

class SiteTags
{
    /**
     * Return cache tag for the current site
     */
    public static function sites($sites = null): array
    {
        if (is_string($sites) && $sites === 'any') {
            $sites = is_multisite() ? get_sites(['fields' => 'ids']) : [get_current_blog_id()];
        } elseif (is_numeric($sites)) {
            $sites = [$sites];
        } elseif (is_null($sites)) {
            $sites = [get_current_blog_id()];
        }

        if (is_array($sites)) {
            return collect($sites)
                ->map(fn ($site) => $site instanceof WP_Site ? $site->blog_id : $site)
                ->map(fn ($site) => sprintf('site:%d', $site))
                ->all();
        }

        return [];
    }
}
