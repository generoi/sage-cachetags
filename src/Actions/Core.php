<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Concerns\TracksAddedTermRelationships;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tag;
use Genero\Sage\CacheTags\Tags\CoreTags;
use WP_Block;
use WP_Comment;
use WP_Post;
use WP_Term;
use WP_User;

class Core implements Action
{
    use TracksAddedTermRelationships;

    /**
     * Statuses a template (part) renders publicly in: get_block_template()
     * (block_template_part()) also returns drafts and auto-drafts. The
     * core/template-part block only renders published parts, so this
     * over-purges a little for those.
     */
    const RENDERED_TEMPLATE_STATUSES = ['publish', 'draft', 'auto-draft'];

    /** Post ids already tagged from a block this request, to avoid re-adding the
     * same post:{id} for every inner block carrying the postId context. */
    protected array $taggedBlockPosts = [];

    /**
     * Term name, slug and parent before wp_update_term() writes them, to compare
     * in onMenuLinkedTermEdited().
     *
     * @var array<int, array{0: string, 1: string, 2: int}>
     */
    protected array $termsBeforeEdit = [];

    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_action('template_redirect', [$this, 'addTemplateCacheTags']);
        \add_action('parse_request', [$this, 'addSitemapCacheTags']);
        \add_filter('render_block', [$this, 'addBlockCacheTags'], 10, 3);
        \add_filter('wp_nav_menu_args', [$this, 'addNavMenuCacheTags']);
        // block_template_part() goes through get_block_template(); the
        // core/template-part block doesn't (tagged in addBlockCacheTags).
        \add_filter('pre_get_block_template', [$this, 'addTemplatePartCacheTags'], 10, 3);
        \add_filter('template_include', [$this, 'addBlockTemplateCacheTags'], PHP_INT_MAX);
        \add_action('transition_post_status', [$this, 'onBlockTemplateStatusTransition'], 10, 3);
        \add_action('before_delete_post', [$this, 'onBlockTemplateDelete']);

        // Clear caches
        \add_action('transition_post_status', [$this, 'onPostStatusTransition'], 10, 3);
        \add_action('before_delete_post', [$this, 'onPostDelete']);
        \add_action('transition_comment_status', [$this, 'onCommentStatusTransition'], 10, 3);
        \add_action('comment_post', [$this, 'onCommentPost'], 10, 3);
        \add_action('edit_comment', [$this, 'onCommentEdit']);
        \add_action('deleted_comment', [$this, 'onCommentDelete'], 10, 2);
        \add_action('saved_term', [$this, 'onTermSave'], 10, 4);
        \add_action('delete_term', [$this, 'onTermDelete'], 10, 3);
        \add_action('added_term_relationship', [$this, 'onTermRelationshipAdded'], 10, 3);
        \add_action('set_object_terms', [$this, 'onTermSet'], 10, 6);
        \add_action('updated_post_meta', [$this, 'onPostMetaUpdate'], 10, 3);
        \add_action('added_post_meta', [$this, 'onPostMetaUpdate'], 10, 3);
        \add_action('deleted_post_meta', [$this, 'onPostMetaUpdate'], 10, 3);
        \add_action('updated_term_meta', [$this, 'onTermMetaUpdate'], 10, 3);
        \add_action('added_term_meta', [$this, 'onTermMetaUpdate'], 10, 3);
        \add_action('edit_attachment', [$this, 'onAttachmentEdit']);
        \add_action('updated_option', [$this, 'onOptionUpdate'], 10, 3);
        \add_action('wp_update_nav_menu', [$this, 'onMenuUpdate']);
        \add_action('wp_delete_nav_menu', [$this, 'onMenuDelete']);
        \add_action('wp_update_nav_menu_item', [$this, 'onMenuUpdate']);
        \add_action('post_updated', [$this, 'onMenuLinkedPostUpdate'], 10, 3);
        \add_action('transition_post_status', [$this, 'onMenuLinkedScheduledPublish'], 10, 3);
        \add_action('before_delete_post', [$this, 'onMenuLinkedPostDelete']);
        \add_action('edit_terms', [$this, 'onMenuLinkedTermEdit'], 10, 2);
        \add_action('edited_term', [$this, 'onMenuLinkedTermEdited'], 10, 3);
        \add_action('pre_delete_term', [$this, 'onMenuLinkedTermDelete'], 10, 2);
        \add_action('updated_option', [$this, 'onMenuLocationsUpdate'], 10, 3);
        \add_action('delete_user', [$this, 'onUserDelete'], 10, 3);
        \add_action('profile_update', [$this, 'onUserUpdate']);
        \add_action('user_register', [$this, 'onUserCreate']);
        \add_action('set_user_role', [$this, 'onUserRoleChange'], 10, 3);
    }

    /**
     * Tag an XML sitemap. Core's (wp-sitemap*.xml) and Yoast's (*sitemap*.xml)
     * both route through the `sitemap` query var, and Yoast prints and exits on
     * pre_get_posts, before template_redirect, so this runs on parse_request.
     * A sitemap lists every published post and term with its last modified
     * date, so any change to one changes it.
     */
    public function addSitemapCacheTags(\WP $wp): void
    {
        if (empty($wp->query_vars['sitemap'])) {
            return;
        }

        $this->cacheTags->add([
            ...CoreTags::anyArchive('any'),
            ...CoreTags::anyTerm('any'),
        ]);
    }

    /**
     * Add default cache tags based on the template.
     */
    public function addTemplateCacheTags(): void
    {
        if (is_feed()) {
            // A feed prints its items itself, not through blocks or teasers that
            // would tag them, so tag what it lists. The cases below then tag it
            // like its HTML page: posts joining or leaving the listing.
            global $wp_query;
            $this->cacheTags->add(CoreTags::posts($wp_query->posts ?? []));
        }

        switch (true) {
            case is_search():
                // Checked before the singular and term cases, as template-loader
                // does: `/no-such-page/?s=x` sets is_page() and is_search() with
                // no queried object, and renders the search template.
                $this->cacheTags->add(CoreTags::archive('post'));
                break;
            case is_single():
            case is_page():
                $this->cacheTags->add(CoreTags::queriedObject());
                break;
            case is_category():
            case is_tag():
            case is_tax():
                $this->cacheTags->add([
                    ...CoreTags::queriedObject(),
                    ...CoreTags::taxonomy(\get_queried_object()->taxonomy),
                ]);
                break;
            case is_home():
                $this->cacheTags->add(CoreTags::archive('post'));
                break;
            case is_post_type_archive():
                $this->cacheTags->add(CoreTags::archive(get_query_var('post_type')));
                break;
            case is_author():
                $this->cacheTags->add(CoreTags::users(get_query_var('author')));
                break;
            case is_attachment():
                $this->cacheTags->add(CoreTags::queriedObject());
                break;
            case is_date():
                // Listings of any post that publishes/unpublishes into them.
                $this->cacheTags->add(CoreTags::archive('post'));
                break;
        }
    }

    /**
     * Tag the menu rendered by a classic-theme wp_nav_menu() call. Block-theme
     * navigation is tagged via its wp_navigation post id in addBlockCacheTags.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function addNavMenuCacheTags(array $args): array
    {
        // Resolve the menu the way wp_nav_menu() does: the `menu` arg, else the
        // theme location's menu, else — with no location — the first menu that
        // has items, which it renders for an unknown or empty `menu`.
        $tags = ! empty($args['menu']) ? CoreTags::menu($args['menu']) : [];

        if (! $tags && ! empty($args['theme_location'])) {
            $tags = CoreTags::navigation($args['theme_location']);
        } elseif (! $tags) {
            foreach (wp_get_nav_menus() as $menu) {
                if (wp_get_nav_menu_items($menu->term_id, ['update_post_term_cache' => false])) {
                    $tags = [Tag::menu($menu->term_id)];
                    break;
                }
            }
        }

        $this->cacheTags->add($tags);

        return $args;
    }

    /**
     * @param  array<string, mixed>  $block  WordPress block array
     */
    public function addBlockCacheTags(string $content, array $block, WP_Block $instance): string
    {
        if (is_admin()) {
            return $content;
        }
        // Eg relevanssi indexing
        if (doing_action('wp_after_insert_post')) {
            return $content;
        }

        $attributes = $block['attrs'] ?? [];
        $tags = [];

        if (isset($instance->context['postId']) && ! isset($this->taggedBlockPosts[$instance->context['postId']])) {
            $this->taggedBlockPosts[$instance->context['postId']] = true;
            $tags[] = CoreTags::posts($instance->context['postId']);
        }

        if (isset($instance->context['commentId'])) {
            $tags[] = CoreTags::comments($instance->context['commentId']);
        }

        if (isset($attributes['ref'])) {
            // @note that these realistically these might be deleted and point to unexisting posts
            $tags[] = CoreTags::posts($attributes['ref']);
        }

        switch ($block['blockName']) {
            case 'core/categories':
                $tags[] = CoreTags::anyTerm('category');
                break;
            case 'core/comments':
                // Individual comments are tagged via the commentId context on
                // the comment-template inner blocks above.
                break;
            case 'core/archives':
            case 'core/calendar':
                $tags[] = CoreTags::archive('post');
                break;
            case 'core/avatar':
                if (! empty($attributes['userId'])) {
                    $tags[] = CoreTags::users($attributes['userId']);
                }
                break;
            case 'core/site-title':
                $tags[] = CoreTags::option('blogname');
                break;
            case 'core/site-tagline':
                $tags[] = CoreTags::option('blogdescription');
                break;
            case 'core/site-logo':
                $tags[] = CoreTags::option('site_logo');
                break;
            case 'core/post-author-name':
            case 'core/post-author':
                $authorId = isset($instance->context['postId'])
                    ? get_post_field('post_author', $instance->context['postId'])
                    : get_query_var('author');

                $tags[] = CoreTags::users([$authorId]);
                break;
            case 'core/tag-cloud':
                $tags[] = CoreTags::anyTerm('post_tag');
                break;
            case 'core/page-list':
                $tags[] = CoreTags::archive('page');
                break;
            case 'core/latest-posts':
                $tags[] = CoreTags::archive('post');
                break;
            case 'core/latest-comments':
                $tags[] = CoreTags::archive('comment');
                break;
            case 'core/navigation-link':
                if (($attributes['kind'] ?? '') === 'post-type' && ! empty($attributes['id'])) {
                    $tags[] = CoreTags::posts($attributes['id']);
                }
                break;
            case 'core/query':
                $tags[] = CoreTags::archive($attributes['query']['postType'] ?? 'post');
                break;
            case 'core/post-terms':
                if (! empty($attributes['term'])) {
                    $postId = $instance->context['postId'] ?? get_the_ID();
                    $tags[] = CoreTags::terms(get_the_terms($postId, $attributes['term']));
                }
                break;
            case 'core/template-part':
                // Core renders a part only from the active theme, from its post
                // or else its file; either way the key is theme + slug.
                $theme = $attributes['theme'] ?? get_stylesheet();
                if (! empty($attributes['slug']) && $theme === get_stylesheet()) {
                    $tags[] = $this->blockTemplateTags("{$theme}//{$attributes['slug']}", 'wp_template_part');
                }
                break;
        }

        $this->cacheTags->add($tags);

        return $content;
    }

    /**
     * Clear cache of post where a new comment was posted if approved.
     */
    public function onCommentPost(int $commentId, $commentApproved, array $commentData): void
    {
        if ($commentApproved !== 1) {
            return;
        }
        if (! isset($commentData['comment_post_ID'])) {
            return;
        }

        $this->cacheTags->clear(CoreTags::posts($commentData['comment_post_ID']));
    }

    /**
     * Clear cache of comments and their corresponding posts if needed.
     */
    public function onCommentStatusTransition(string $newStatus, string $oldStatus, WP_Comment $comment): void
    {
        $cacheTags = [
            ...CoreTags::comments($comment),
        ];

        // If attached to a post, and it either was or is approved, clear the post cache.
        $isCommentStatusChanged = ($newStatus === 'approved' || $oldStatus === 'approved');
        if (! empty($comment->comment_post_ID) && $isCommentStatusChanged) {
            $cacheTags = [
                ...$cacheTags,
                ...CoreTags::posts($comment->comment_post_ID),
            ];
        }

        $this->cacheTags->clear($cacheTags);
    }

    /**
     * When a comment's content is edited, clear it and its post.
     */
    public function onCommentEdit(int $commentId): void
    {
        $comment = get_comment($commentId);
        if (! $comment instanceof WP_Comment) {
            return;
        }

        $this->cacheTags->clear([
            ...CoreTags::comments($commentId),
            ...CoreTags::posts((int) $comment->comment_post_ID),
        ]);
    }

    /**
     * When a comment is permanently deleted, clear it and its post.
     */
    public function onCommentDelete(int $commentId, WP_Comment $comment): void
    {
        $this->cacheTags->clear([
            ...CoreTags::comments($commentId),
            ...CoreTags::posts((int) $comment->comment_post_ID),
        ]);
    }

    /**
     * This hook is misleading but it runs for scheduled posts, editing a
     * published post as well as manually publishing/unpublishing a post.
     */
    public function onPostStatusTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($this->isEmbeddedPostType($post->post_type)) {
            $this->cacheTags->clear(CoreTags::posts($post->ID));

            return;
        }

        if (! CoreTags::isCacheablePostType($post->post_type)) {
            return;
        }

        $isStatusChanged = $newStatus !== $oldStatus;
        $cacheTags = [
            ...CoreTags::posts($post->ID),
            // Coarse "any post of this type changed" tag — the fallback target
            // when a response collapses too many individual post tags.
            ...CoreTags::anyArchive($post->post_type),
        ];

        // If it's new or unpublished, clear the taxonomy pages and the archive page
        if ($isStatusChanged && ($newStatus === 'publish' || $oldStatus === 'publish')) {
            $taxonomies = array_intersect(
                CoreTags::getCacheableTaxonomies(),
                get_post_taxonomies($post)
            );

            // @TODO: Could potentially compare new to old terms and only clear those.
            $cacheTags = [
                ...$cacheTags,
                ...CoreTags::archive($post->post_type),
                ...CoreTags::taxonomy(array_values($taxonomies)),
            ];
        }

        $this->cacheTags->clear($cacheTags);
    }

    /**
     * On permanent deletion (which skips the trash transition, e.g. for
     * attachments or EMPTY_TRASH), clear the post and the listings it was in.
     */
    public function onPostDelete(int $postId): void
    {
        $post = get_post($postId);
        if (! $post) {
            return;
        }

        if ($this->isEmbeddedPostType($post->post_type)) {
            $this->cacheTags->clear(CoreTags::posts($post->ID));

            return;
        }

        if (! CoreTags::isCacheablePostType($post->post_type)) {
            return;
        }

        $taxonomies = array_intersect(
            CoreTags::getCacheableTaxonomies(),
            get_post_taxonomies($post)
        );

        $this->cacheTags->clear([
            ...CoreTags::posts($post->ID),
            ...CoreTags::anyArchive($post->post_type),
            ...CoreTags::archive($post->post_type),
            ...CoreTags::taxonomy(array_values($taxonomies)),
        ]);
    }

    /**
     * Reusable blocks (`wp_block`) and block-theme navigation menus
     * (`wp_navigation`) are embedded into other pages by reference (tagged
     * `post:{id}` there via the block `ref` attribute) rather than rendered as
     * their own page. They aren't a cacheable post type, but a change to one
     * must still purge that `post:{id}`.
     */
    protected function isEmbeddedPostType(string $postType): bool
    {
        return in_array($postType, ['wp_block', 'wp_navigation'], true);
    }

    /**
     * Tag a template part rendered with block_template_part(), which resolves
     * it through get_block_template(). Its pre_ filter runs on every call; the
     * get_block_template filter only on the theme-file fallback, as a
     * customised (database) part is returned before it. Tagged also when there
     * is no part yet, so its first customisation still purges the page.
     *
     * The id and type are untyped: a third party calling get_block_template()
     * with null would otherwise turn this filter into a TypeError on every page.
     *
     * @param  \WP_Block_Template|null  $pre
     * @param  string|mixed  $id
     * @param  string|mixed  $templateType
     * @return \WP_Block_Template|null
     */
    public function addTemplatePartCacheTags($pre, $id, $templateType)
    {
        if ($templateType === 'wp_template_part' && is_string($id) && ! is_admin()) {
            $this->cacheTags->add($this->blockTemplateTags($id, $templateType));
        }

        return $pre;
    }

    /**
     * Tag the block template the page resolved to (locate_block_template() sets
     * its `{theme}//{slug}` id before template_include).
     */
    public function addBlockTemplateCacheTags($template)
    {
        global $_wp_current_template_id;

        if (is_string($_wp_current_template_id) && $_wp_current_template_id !== '') {
            $this->cacheTags->add($this->blockTemplateTags($_wp_current_template_id, 'wp_template'));
        }

        return $template;
    }

    /**
     * Publishing, updating, trashing or unpublishing a template or template part
     * clears its key: the pages rendering it, whether from this post or the
     * theme file it overrides.
     */
    public function onBlockTemplateStatusTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        $rendered = self::RENDERED_TEMPLATE_STATUSES;
        if ((in_array($newStatus, $rendered, true) || in_array($oldStatus, $rendered, true)) && $this->isBlockTemplatePostType($post->post_type)) {
            $this->cacheTags->clear($this->blockTemplatePurgeTags($post));
        }
    }

    /**
     * "Clear customizations" force-deletes the post (no status transition), and
     * pages fall back to the theme file under the same key.
     */
    public function onBlockTemplateDelete(int $postId): void
    {
        $post = get_post($postId);
        if ($post instanceof WP_Post && in_array($post->post_status, self::RENDERED_TEMPLATE_STATUSES, true) && $this->isBlockTemplatePostType($post->post_type)) {
            $this->cacheTags->clear($this->blockTemplatePurgeTags($post));
        }
    }

    protected function isBlockTemplatePostType(string $postType): bool
    {
        return in_array($postType, ['wp_template', 'wp_template_part'], true);
    }

    /**
     * The tags a page rendering a template (part) carries. Filtered by the
     * Polylang action to qualify a part with the page's language.
     *
     * @return Tag[]
     */
    protected function blockTemplateTags(string $id, string $templateType): array
    {
        // A plugin's own template resolves as `{plugin}//{slug}` (WooCommerce:
        // `woocommerce/woocommerce//single-product`), but its customisation is
        // saved under the active theme, which is what the purge key uses. Every
        // template a page renders comes from the active theme or overrides one
        // for it, so key them all by the active theme.
        $parts = explode('//', $id, 2);
        if (count($parts) === 2) {
            $id = get_stylesheet().'//'.$parts[1];
        }

        return Tag::fromMany((array) apply_filters('cachetags/block-template-tags', CoreTags::blockTemplate($id, $templateType), $id, $templateType));
    }

    /**
     * The tags to clear when a template (part) post changes. Filtered by the
     * Polylang action to map a translated part's post to its languages.
     *
     * @return Tag[]
     */
    protected function blockTemplatePurgeTags(WP_Post $post): array
    {
        $theme = CoreTags::blockTemplateTheme($post);
        $slug = CoreTags::blockTemplateSlug($post);
        $tags = CoreTags::blockTemplate("{$theme}//{$slug}", $post->post_type);

        return Tag::fromMany((array) apply_filters('cachetags/block-template-purge-tags', $tags, $post, $theme, $slug));
    }

    /**
     * When the terms of an object change, clear the pages of the terms that
     * were added or removed, and the object itself.
     *
     * set_object_terms fires on every wp_set_object_terms() call, also when the
     * term set is unchanged (an importer re-saving every listing each hour), so
     * only the difference is purged and nothing at all when there is none (see
     * TracksAddedTermRelationships for why the added terms aren't the arguments).
     */
    public function onTermSet(int $objectId, array $terms, array $taxonomyIds, string $taxonomy, bool $append = false, array $oldTaxonomyIds = []): void
    {
        $added = $this->takeAddedTermRelationships($objectId, $taxonomy);

        if (! CoreTags::isCacheableTaxonomy($taxonomy)) {
            return;
        }

        $removed = $append ? [] : array_diff(array_map('intval', $oldTaxonomyIds), array_map('intval', $taxonomyIds));
        if (! $added && ! $removed) {
            return;
        }

        // Resolve term_taxonomy_ids → term_ids in a single query: $terms can be
        // slugs/names (which CoreTags would mis-format as term:0), and the
        // REMOVED terms must be purged too so a reassigned post's old term
        // archive isn't left stale.
        $taxonomyIds = array_values(array_unique([...$added, ...$removed]));
        $termIds = $taxonomyIds ? get_terms([
            'taxonomy' => $taxonomy,
            'term_taxonomy_id' => $taxonomyIds,
            'hide_empty' => false,
            'fields' => 'ids',
            // Polylang filters term queries by the current language otherwise,
            // dropping another language's terms set on the object.
            'lang' => '',
        ]) : [];
        if (! is_array($termIds)) {
            $termIds = [];
        }

        // Clear the term pages but not the regular term tags since values
        // haven't changed.
        $cacheTags = [
            ...CoreTags::termPages($termIds),
        ];

        // If it was set on a post, clear the post as well, and with a
        // %category%-style permalink the menus linking to its now-changed URL.
        $post = get_post($objectId);
        if ($post instanceof WP_Post) {
            $cacheTags = [
                ...$cacheTags,
                ...CoreTags::posts($objectId),
            ];

            if (self::isPermalinkTaxonomy($taxonomy, $post->post_type) && $this->canBeInMenus($post)) {
                $cacheTags = [...$cacheTags, ...$this->linkingMenus($objectId, 'post_type')];
            }
        }

        $this->cacheTags->clear($cacheTags);
    }

    /**
     * When an attachment is edited (title, alt, caption, replaced file), clear
     * the attachment itself so posts that tag it as featured media or render it
     * are purged.
     */
    public function onAttachmentEdit(int $attachmentId): void
    {
        $this->cacheTags->clear(CoreTags::posts($attachmentId));
    }

    /**
     * Whenever a post meta is added, updated or deleted, clear the post.
     *
     * The first argument is a meta id for added/updated_post_meta but an array
     * of ids for deleted_post_meta; it is unused either way.
     */
    public function onPostMetaUpdate($metaId, int $objectId, string $metaKey): void
    {
        if (! CoreTags::isCacheablePostType($objectId)) {
            return;
        }
        if (wp_is_post_revision($objectId)) {
            return;
        }

        if (! CoreTags::isCacheablePostMeta($metaKey, $objectId)) {
            return;
        }

        $this->cacheTags->clear(CoreTags::posts($objectId));
    }

    /**
     * When a term is updated, clear relevant caches.
     */
    public function onTermSave(int $termId, int $taxonomyId, string $taxonomy, bool $updated): void
    {
        if (! CoreTags::isCacheableTaxonomy($taxonomy)) {
            return;
        }

        $cacheTags = [
            ...CoreTags::terms($termId),
            ...CoreTags::termPages($termId),
            ...CoreTags::anyTerm($taxonomy),
        ];

        // If it's a new term, also clear the taxonomy listings.
        if (! $updated) {
            $cacheTags = [
                ...$cacheTags,
                ...CoreTags::taxonomy($taxonomy),
            ];
        }

        $this->cacheTags->clear($cacheTags);
    }

    /**
     * When a term is deleted, clear it and its taxonomy listings.
     */
    public function onTermDelete(int $term, int $termTaxonomyId, string $taxonomy): void
    {
        if (! CoreTags::isCacheableTaxonomy($taxonomy)) {
            return;
        }

        $this->cacheTags->clear([
            ...CoreTags::terms($term),
            ...CoreTags::termPages($term),
            ...CoreTags::taxonomy($taxonomy),
            ...CoreTags::anyTerm($taxonomy),
        ]);
    }

    /**
     * When term meta is added or updated, clear the term.
     */
    public function onTermMetaUpdate($metaId, int $termId, string $metaKey): void
    {
        $term = get_term($termId);
        if (! $term instanceof WP_Term || ! CoreTags::isCacheableTaxonomy($term->taxonomy)) {
            return;
        }

        $this->cacheTags->clear([
            ...CoreTags::terms($termId),
            ...CoreTags::termPages($termId),
        ]);
    }

    /**
     * When a tracked site option changes, clear pages that render it.
     */
    public function onOptionUpdate(string $option, $oldValue, $newValue): void
    {
        if (! in_array($option, CoreTags::getCacheableOptions(), true)) {
            return;
        }

        $this->cacheTags->clear(CoreTags::option($option));
    }

    /**
     * When a menu is updated, clear caches using it.
     *
     * A menu id of 0 is core's doing, not a caller error. wp_ajax_add_menu_item()
     * saves what the "Add to Menu" button submits with
     * `wp_save_nav_menu_items(0, ...)`: the item rows are created up front and
     * only bound to a menu when the screen is saved, so `wp_update_nav_menu_item`
     * fires for an item that is in no menu yet. Nothing renders it at that point,
     * so there is nothing to purge.
     */
    public function onMenuUpdate(int $menuId): void
    {
        if (! $menuId) {
            return;
        }

        $this->cacheTags->clear(CoreTags::menu($menuId));
    }

    /**
     * A deleted menu stops rendering wherever it was shown. wp_delete_nav_menu
     * fires once the term is gone, so CoreTags::menu() can't resolve it; tag it
     * by id. Under Polylang this also covers a language's location, which
     * Polylang unassigns (delete_nav_menu) before the theme mod is saved.
     */
    public function onMenuDelete(int $menuId): void
    {
        if ($menuId) {
            $this->cacheTags->clear(Tag::menu($menuId));
        }
    }

    /**
     * A menu item takes its label and URL live from the post it links to, but
     * pages only carry the menu's `menu:{id}`. Clear the menus linking to a post
     * when a field they render changes: title (the default label), slug, parent
     * (the URL) or status (trashing or unpublishing hides the item). Other saves
     * leave the menus alone, so editing content doesn't purge every page that
     * shows the menu.
     *
     * Known limit: a child page's URL also changes when its parent's slug does,
     * but menus linking to the child aren't cleared.
     */
    public function onMenuLinkedPostUpdate(int $postId, WP_Post $after, WP_Post $before): void
    {
        if ($this->canBeInMenus($after) && self::linkFieldsChanged($after, $before)) {
            $this->cacheTags->clear($this->linkingMenus($postId, 'post_type'));
        }
    }

    /**
     * Scheduled posts publish through wp_publish_post(), which never fires
     * post_updated.
     */
    public function onMenuLinkedScheduledPublish(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($oldStatus === 'future' && $newStatus === 'publish' && $this->canBeInMenus($post)) {
            $this->cacheTags->clear($this->linkingMenus($post->ID, 'post_type'));
        }
    }

    /**
     * Only post types offered in the menu editor can have items linking to
     * them, so orders, attachments, menu items and revisions skip the lookup
     * (a query per status change or delete otherwise).
     */
    protected function canBeInMenus(WP_Post $post): bool
    {
        if ($post->post_type === 'nav_menu_item') {
            return false;
        }

        $type = get_post_type_object($post->post_type);

        return $type !== null && ! empty($type->show_in_nav_menus);
    }

    /**
     * Whether a post changed in a way a link to it shows: its title (a menu
     * item's default label), slug, parent or — with a date-based permalink
     * structure — date (its URL), or status (trashing or unpublishing hides the
     * link).
     */
    public static function linkFieldsChanged(WP_Post $after, WP_Post $before): bool
    {
        $fields = ['post_title', 'post_name', 'post_parent', 'post_status'];
        if (self::permalinkUsesDate()) {
            $fields[] = 'post_date';
        }

        foreach ($fields as $field) {
            if ($after->$field !== $before->$field) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether post URLs contain their date (%year%, %monthnum%, …).
     */
    public static function permalinkUsesDate(): bool
    {
        return (bool) preg_match('/%(year|monthnum|day|hour|minute|second)%/', (string) get_option('permalink_structure'));
    }

    /**
     * Whether a post type's URLs contain a term of the taxonomy: %category% in
     * the post permalink structure, %product_cat% in WooCommerce's product base.
     * Custom post type rewrite tags aren't detected.
     */
    public static function isPermalinkTaxonomy(string $taxonomy, string $postType): bool
    {
        $structure = match ($postType) {
            'post' => (string) get_option('permalink_structure'),
            'product' => (string) (((array) get_option('woocommerce_permalinks', []))['product_base'] ?? ''),
            default => '',
        };

        return str_contains($structure, "%{$taxonomy}%");
    }

    /**
     * The term equivalent of linkFieldsChanged(), from a snapshot taken with
     * termLinkFields() before the edit.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    public static function termLinkFields(WP_Term $term): array
    {
        return [$term->name, $term->slug, (int) $term->parent];
    }

    /**
     * Deleting a post removes its menu items (core's _wp_delete_post_menu_item,
     * on delete_post) without saving the menu, so clear the menus before that.
     */
    public function onMenuLinkedPostDelete(int $postId): void
    {
        $post = get_post($postId);
        if ($post instanceof WP_Post && $this->canBeInMenus($post)) {
            $this->cacheTags->clear($this->linkingMenus($postId, 'post_type'));
        }
    }

    public function onMenuLinkedTermEdit(int $termId, string $taxonomy): void
    {
        if (! $this->taxonomyCanBeInMenus($taxonomy)) {
            return;
        }

        $term = get_term($termId, $taxonomy);
        if ($term instanceof WP_Term) {
            $this->termsBeforeEdit[$termId] = self::termLinkFields($term);
        }
    }

    /**
     * Only taxonomies offered in the menu editor can have items linking to
     * them, so internal ones (Polylang's translation groups, rewritten on every
     * relink; nav_menu itself) skip the lookup.
     */
    protected function taxonomyCanBeInMenus(string $taxonomy): bool
    {
        $object = get_taxonomy($taxonomy);

        return $object !== false && ! empty($object->show_in_nav_menus);
    }

    /**
     * Clear the menus linking to a term whose name (the default label), slug or
     * parent (the URL) changed.
     */
    public function onMenuLinkedTermEdited(int $termId, int $termTaxonomyId, string $taxonomy): void
    {
        $before = $this->termsBeforeEdit[$termId] ?? null;
        unset($this->termsBeforeEdit[$termId]);

        $term = get_term($termId, $taxonomy);
        if ($before === null || ! $term instanceof WP_Term || $before === self::termLinkFields($term)) {
            return;
        }

        $this->cacheTags->clear($this->linkingMenus($termId, 'taxonomy', $taxonomy));
    }

    /**
     * Deleting a term removes its menu items (core's _wp_delete_tax_menu_item,
     * on delete_term) without saving the menu, so clear the menus before that.
     */
    public function onMenuLinkedTermDelete(int $termId, string $taxonomy): void
    {
        if ($this->taxonomyCanBeInMenus($taxonomy)) {
            $this->cacheTags->clear($this->linkingMenus($termId, 'taxonomy', $taxonomy));
        }
    }

    /**
     * When a menu location is assigned another menu, or one for the first
     * time, or none, clear the pages that rendered it (their
     * `menu-location:{location}` tag, see CoreTags::navigation()).
     */
    public function onMenuLocationsUpdate(string $option, $oldValue, $newValue): void
    {
        if ($option !== 'theme_mods_'.get_option('stylesheet')) {
            return;
        }

        $old = (array) (is_array($oldValue) ? ($oldValue['nav_menu_locations'] ?? []) : []);
        $new = (array) (is_array($newValue) ? ($newValue['nav_menu_locations'] ?? []) : []);

        $tags = [];
        foreach (array_keys($old + $new) as $location) {
            if ((int) ($old[$location] ?? 0) !== (int) ($new[$location] ?? 0)) {
                $tags[] = CoreTags::menuLocation((string) $location);
            }
        }

        $this->cacheTags->clear($tags);
    }

    /**
     * The `menu:{id}` tags of the menus with an item linking to an object.
     *
     * @return Tag[]
     */
    protected function linkingMenus(int $objectId, string $objectType, string $taxonomy = ''): array
    {
        $items = wp_get_associated_nav_menu_items($objectId, $objectType, $taxonomy);
        if (! $items) {
            return [];
        }

        $menuIds = wp_get_object_terms($items, 'nav_menu', ['fields' => 'ids']);
        if (! is_array($menuIds)) {
            return [];
        }

        return array_map(fn ($menuId) => Tag::menu((int) $menuId), array_unique($menuIds));
    }

    public function onUserDelete(int $userId, ?int $reassign, WP_User $user): void
    {
        $this->cacheTags->clear([
            ...CoreTags::users($userId),
            ...CoreTags::anyUser($user->roles),
        ]);
    }

    public function onUserUpdate(int $userId): void
    {
        $user = get_userdata($userId);

        $this->cacheTags->clear([
            ...CoreTags::users($userId),
            ...($user ? CoreTags::anyUser($user->roles) : []),
        ]);
    }

    public function onUserCreate(int $userId): void
    {
        $user = get_userdata($userId);

        $this->cacheTags->clear([
            ...CoreTags::users($userId),
            ...($user ? CoreTags::anyUser($user->roles) : []),
        ]);
    }

    /**
     * When a user's role changes (incl. programmatically via set_user_role,
     * which doesn't fire profile_update), clear the user and both the old and
     * new role listings.
     *
     * @param  string[]  $oldRoles
     */
    public function onUserRoleChange(int $userId, string $role, array $oldRoles): void
    {
        $this->cacheTags->clear([
            ...CoreTags::users($userId),
            ...CoreTags::anyUser($role),
            ...CoreTags::anyUser($oldRoles),
        ]);
    }
}
