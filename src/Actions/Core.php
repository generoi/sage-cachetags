<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\CoreTags;
use WP_Block;
use WP_Comment;
use WP_Post;
use WP_User;

class Core implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_action('template_redirect', [$this, 'addTemplateCacheTags']);
        \add_filter('render_block', [$this, 'addBlockCacheTags'], 10, 3);
        \add_filter('wp_nav_menu_args', [$this, 'addNavMenuCacheTags']);

        // Clear caches
        \add_action('transition_post_status', [$this, 'onPostStatusTransition'], 10, 3);
        \add_action('before_delete_post', [$this, 'onPostDelete']);
        \add_action('transition_comment_status', [$this, 'onCommentStatusTransition'], 10, 3);
        \add_action('comment_post', [$this, 'onCommentPost'], 10, 3);
        \add_action('edit_comment', [$this, 'onCommentEdit']);
        \add_action('deleted_comment', [$this, 'onCommentDelete'], 10, 2);
        \add_action('saved_term', [$this, 'onTermSave'], 10, 4);
        \add_action('delete_term', [$this, 'onTermDelete'], 10, 3);
        \add_action('set_object_terms', [$this, 'onTermSet'], 10, 4);
        \add_action('updated_post_meta', [$this, 'onPostMetaUpdate'], 10, 3);
        \add_action('added_post_meta', [$this, 'onPostMetaUpdate'], 10, 3);
        \add_action('deleted_post_meta', [$this, 'onPostMetaUpdate'], 10, 3);
        \add_action('updated_term_meta', [$this, 'onTermMetaUpdate'], 10, 3);
        \add_action('added_term_meta', [$this, 'onTermMetaUpdate'], 10, 3);
        \add_action('edit_attachment', [$this, 'onAttachmentEdit']);
        \add_action('updated_option', [$this, 'onOptionUpdate'], 10, 3);
        \add_action('wp_update_nav_menu', [$this, 'onMenuUpdate']);
        \add_action('wp_update_nav_menu_item', [$this, 'onMenuUpdate']);
        \add_action('delete_user', [$this, 'onUserDelete'], 10, 3);
        \add_action('profile_update', [$this, 'onUserUpdate']);
        \add_action('user_register', [$this, 'onUserCreate']);
        \add_action('set_user_role', [$this, 'onUserRoleChange'], 10, 3);
    }

    /**
     * Add default cache tags based on the template.
     */
    public function addTemplateCacheTags(): void
    {
        switch (true) {
            case is_feed():
                // Skip feeds
                break;
            case is_single():
            case is_page():
                $this->cacheTags->add([
                    ...CoreTags::queriedObject(),
                ]);
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
                $this->cacheTags->add([
                    ...CoreTags::archive('post'),
                ]);
                break;
            case is_post_type_archive():
                $this->cacheTags->add([
                    ...CoreTags::archive(get_query_var('post_type')),
                ]);
                break;
            case is_author():
                $this->cacheTags->add([
                    ...CoreTags::users(get_query_var('author')),
                ]);
                break;
            case is_attachment():
                $this->cacheTags->add([
                    ...CoreTags::queriedObject(),
                ]);
                break;
            case is_date():
            case is_search():
                // Listings of any post that publishes/unpublishes into them.
                $this->cacheTags->add([
                    ...CoreTags::archive('post'),
                ]);
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
        if (! empty($args['theme_location'])) {
            $this->cacheTags->add(CoreTags::navigation($args['theme_location']));
        } elseif (! empty($args['menu'])) {
            $this->cacheTags->add(CoreTags::menu($args['menu']));
        }

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

        if (isset($instance->context['postId'])) {
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
                if ($attributes['kind'] === 'post-type') {
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

        $this->cacheTags->clear([
            ...CoreTags::posts($commentData['comment_post_ID']),
        ]);
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
        if (! $post || ! CoreTags::isCacheablePostType($post->post_type)) {
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
     * When a term is added to an object, clear caches.
     */
    public function onTermSet(int $objectId, array $terms, array $taxonomyIds, string $taxonomy): void
    {
        if (! CoreTags::isCacheableTaxonomy($taxonomy)) {
            return;
        }

        $object = get_post($objectId);
        // Clear the term pages but not the regular term tags since values
        // haven't changed.
        $cacheTags = [
            ...CoreTags::termPages($terms),
        ];

        // If it was set on a post, clear the post as well.
        if ($object) {
            $cacheTags = [
                ...$cacheTags,
                ...CoreTags::posts($objectId),
            ];
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
        $this->cacheTags->clear([
            ...CoreTags::posts($attachmentId),
        ]);
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

        $this->cacheTags->clear([
            ...CoreTags::posts($objectId),
        ]);
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
        if (! $term instanceof \WP_Term || ! CoreTags::isCacheableTaxonomy($term->taxonomy)) {
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

        $this->cacheTags->clear([
            ...CoreTags::option($option),
        ]);
    }

    /**
     * When a menu is updated, clear caches using it.
     */
    public function onMenuUpdate(int $menuId): void
    {
        $this->cacheTags->clear([
            ...CoreTags::menu($menuId),
        ]);
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
        $this->cacheTags->clear([
            ...CoreTags::users($userId),
            ...CoreTags::anyUser(get_userdata($userId)->roles),
        ]);
    }

    public function onUserCreate(int $userId): void
    {
        $this->cacheTags->clear([
            ...CoreTags::users($userId),
            ...CoreTags::anyUser(get_userdata($userId)->roles),
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
