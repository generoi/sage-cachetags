<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\CoreTags;
use WP_Post;

/**
 * Tag every cacheable page and REST response with a single base tag, so one
 * purge clears all WordPress-served pages at once while static assets (which
 * never carry it) stay cached. Wired by Bootstrap with the configured tag name;
 * disable by setting the base tag to null.
 *
 * Global styles and the Customizer's Additional CSS are inlined into every page,
 * so a change to them clears the base tag. So does a template in the hierarchy
 * appearing or disappearing that has no theme file:
 * it changes which template pages resolve to, and those pages carry another
 * template's tag. Other template and template part saves purge only the pages
 * rendering them (Core's `template:` / `template-part:` tags). With the base
 * tag disabled nothing purges on these saves.
 */
class BaseTag implements Action
{
    public function __construct(protected CacheTags $cacheTags, protected string $tag = 'page') {}

    public function bind(): void
    {
        \add_action('template_redirect', [$this, 'addBaseTag']);
        \add_action('rest_api_init', [$this, 'addBaseTag']);

        \add_action('transition_post_status', [$this, 'onPostStatusTransition'], 10, 3);
        // A reset ("Clear customizations") force-deletes the post, which skips
        // transition_post_status.
        \add_action('before_delete_post', [$this, 'onPostDelete'], 10, 2);
    }

    public function addBaseTag(): void
    {
        $this->cacheTags->add($this->tag);
    }

    public function onPostStatusTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($newStatus !== 'publish' && $oldStatus !== 'publish') {
            return;
        }

        $clear = match ($post->post_type) {
            // Opening the block editor inserts an empty published stub when the
            // theme has none (WP_Theme_JSON_Resolver); it changes nothing.
            'wp_global_styles' => $this->isForActiveTheme($post) && ! ($oldStatus === 'new' && $this->isEmptyGlobalStyles($post)),
            // Customizer → Additional CSS, inlined into every page.
            'custom_css' => $this->isForActiveTheme($post),
            'wp_template' => $newStatus !== $oldStatus && $this->changesTemplateResolution($post),
            default => false,
        };

        if ($clear) {
            $this->cacheTags->clear($this->tag);
        }
    }

    public function onPostDelete(int $postId, ?WP_Post $post = null): void
    {
        $post ??= get_post($postId);
        if (! $post instanceof WP_Post || $post->post_status !== 'publish') {
            return;
        }

        $clear = match ($post->post_type) {
            'wp_global_styles', 'custom_css' => $this->isForActiveTheme($post),
            'wp_template' => $this->changesTemplateResolution($post),
            default => false,
        };

        if ($clear) {
            $this->cacheTags->clear($this->tag);
        }
    }

    /**
     * Global styles and Additional CSS are stored per theme; saving another
     * theme's (a Customizer or Site Editor preview of it) changes no page.
     * Global styles carry a wp_theme term, Additional CSS the stylesheet as its
     * post_name.
     */
    protected function isForActiveTheme(WP_Post $post): bool
    {
        return $post->post_type === 'custom_css'
            ? $post->post_name === get_stylesheet()
            : CoreTags::blockTemplateTheme($post) === get_stylesheet();
    }

    /**
     * The `{"version": N, "isGlobalStylesUserThemeJSON": true}` stub core
     * creates for a theme with no user styles yet.
     */
    protected function isEmptyGlobalStyles(WP_Post $post): bool
    {
        $styles = json_decode($post->post_content, true);

        return is_array($styles) && array_diff(array_keys($styles), ['version', 'isGlobalStylesUserThemeJSON']) === [];
    }

    /**
     * Whether a template of the active theme appearing or disappearing changes
     * which template pages resolve to: one in the template hierarchy that no
     * theme file or plugin backs. A `wp-custom-template-*` template only renders
     * for the posts assigned to it, and assigning one purges that post.
     */
    protected function changesTemplateResolution(WP_Post $post): bool
    {
        $theme = CoreTags::blockTemplateTheme($post);
        $slug = CoreTags::blockTemplateSlug($post);

        return $theme === get_stylesheet()
            && ! str_starts_with($slug, 'wp-custom-template-')
            && ! get_block_file_template("{$theme}//{$slug}", 'wp_template')
            && ! $this->isPluginTemplate($slug);
    }

    /**
     * A template a plugin provides already renders under its slug, so its
     * customisation replaces it under the same key (Core's `template:` tag)
     * rather than changing resolution. WooCommerce registers its templates
     * with register_block_template() but serves its product taxonomy templates
     * (taxonomy-product_cat, …) itself.
     */
    protected function isPluginTemplate(string $slug): bool
    {
        if (class_exists('WP_Block_Templates_Registry') && method_exists('WP_Block_Templates_Registry', 'get_by_slug') && \WP_Block_Templates_Registry::get_instance()->get_by_slug($slug)) {
            return true;
        }

        return class_exists('WooCommerce') && str_starts_with($slug, 'taxonomy-product_');
    }
}
