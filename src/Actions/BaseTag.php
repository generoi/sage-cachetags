<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use WP_Post;

/**
 * Tag every cacheable page and REST response with a single base tag, so one
 * purge clears all WordPress-served pages at once while static assets (which
 * never carry it) stay cached. Wired by Bootstrap with the configured tag name;
 * disable by setting the base tag to null.
 *
 * Site Editor content (template parts, templates, global styles) is rendered
 * into every page, so a change to it clears the base tag. With the base tag
 * disabled nothing purges on those saves.
 */
class BaseTag implements Action
{
    /**
     * Post types the Site Editor saves that render on every page: a header or
     * footer template part, a template, the inlined global stylesheet. They are
     * _builtin and not public, so Core never purges on them.
     */
    const SITE_WIDE_POST_TYPES = ['wp_template_part', 'wp_template', 'wp_global_styles'];

    public function __construct(protected CacheTags $cacheTags, protected string $tag = 'page') {}

    public function bind(): void
    {
        \add_action('template_redirect', [$this, 'addBaseTag']);
        \add_action('rest_api_init', [$this, 'addBaseTag']);

        \add_action('transition_post_status', [$this, 'onPostStatusTransition'], 10, 3);
        // "Clear customizations" force-deletes the post to fall back to the
        // theme file, which skips transition_post_status.
        \add_action('before_delete_post', [$this, 'onPostDelete'], 10, 2);
    }

    public function addBaseTag(): void
    {
        $this->cacheTags->add($this->tag);
    }

    /**
     * A site-wide post is published, updated or unpublished (trashed).
     */
    public function onPostStatusTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($newStatus !== 'publish' && $oldStatus !== 'publish') {
            return;
        }

        if (in_array($post->post_type, self::SITE_WIDE_POST_TYPES, true)) {
            $this->cacheTags->clear($this->tag);
        }
    }

    /**
     * A published site-wide post is deleted, e.g. reset to the theme's file.
     */
    public function onPostDelete(int $postId, ?WP_Post $post = null): void
    {
        $post ??= get_post($postId);
        if (! $post instanceof WP_Post || $post->post_status !== 'publish') {
            return;
        }

        if (in_array($post->post_type, self::SITE_WIDE_POST_TYPES, true)) {
            $this->cacheTags->clear($this->tag);
        }
    }
}
