<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\CoreTags;
use Genero\Sage\CacheTags\Tags\PolylangTags;
use WP_Post;

class Polylang implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        if (did_action('pll_init')) {
            $this->init();
        } else {
            \add_action('pll_init', [$this, 'init']);
        }
    }

    public function init(): void
    {
        \add_filter(CacheTags::FILTER_TAGS, [$this, 'filterArchiveTags'], 5);
        \add_action('transition_post_status', [$this, 'onPostStatusTransition'], 9, 3);
        \add_action('template_redirect', [$this, 'addLanguageTag']);
        \add_filter('rest_post_dispatch', [$this, 'addLanguageTag']);
    }

    /**
     * Add the current language as a cache tag to every page.
     */
    public function addLanguageTag(): void
    {
        $this->cacheTags->add(PolylangTags::language());
    }

    /**
     * Replace generic archive tags for translated post types with
     * language-specific variants when a language context is available.
     * On the purge side (no language context), tags pass through
     * unchanged — any bare archive tags are harmless no-ops since
     * the store only has language-suffixed entries.
     *
     * @param  string[]  $tags
     * @return string[]
     */
    public function filterArchiveTags(array $tags): array
    {
        $lang = pll_current_language();
        if (! $lang) {
            return $tags;
        }

        return array_map(function (string $tag) use ($lang) {
            $parts = explode(':', $tag);

            if (count($parts) === 2 && $parts[0] === 'archive' && pll_is_translated_post_type($parts[1])) {
                return "{$tag}:{$lang}";
            }

            return $tag;
        }, $tags);
    }

    /**
     * Add language-specific archive tag for purging when a translated
     * post transitions to or from publish.
     */
    public function onPostStatusTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($newStatus === $oldStatus || ($newStatus !== 'publish' && $oldStatus !== 'publish')) {
            return;
        }

        if (! CoreTags::isCacheablePostType($post->post_type) || ! pll_is_translated_post_type($post->post_type)) {
            return;
        }

        $lang = pll_get_post_language($post->ID);
        if ($lang) {
            $this->cacheTags->clear(["archive:{$post->post_type}:{$lang}"]);
        }
    }
}
