<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\CoreTags;
use WP_Comment;
use WP_Post;
use WP_User;

class Core implements Action
{
    protected CacheTags $cacheTags;

    public function __construct(CacheTags $cacheTags)
    {
        $this->cacheTags = $cacheTags;
    }

    public function bind(): void
    {
        \add_action('template_redirect', [$this, 'addTemplateCacheTags']);

        // Clear caches
        \add_action('transition_post_status', [$this, 'onPostStatusTransition'], 10, 3);
        \add_action('transition_comment_status', [$this, 'onCommentStatusTransition'], 10, 3);
        \add_action('comment_post', [$this, 'onCommentPost'], 10, 3);
        \add_action('saved_term', [$this, 'onTermSave'], 10, 4);
        \add_action('set_object_terms', [$this, 'onTermSet'], 10, 4);
        \add_action('updated_post_meta', [$this, 'onPostMetaUpdate'], 10, 3);
        \add_action('wp_update_nav_menu', [$this, 'onMenuUpdate']);
        \add_action('delete_user', [$this, 'onUserDelete'], 10, 3);
        \add_action('profile_update', [$this, 'onUserUpdate']);
        \add_action('user_register', [$this, 'onUserCreate']);
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
        }
    }

    /**
     * Clear cache of post where a new comment was posted if approved.
     */
    public function onCommentPost(int $commentId, $commentApproved, array $commentData): void
    {
        if ($commentApproved !== 1) {
            return;
        }
        if (!isset($commentData['comment_post_ID'])) {
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
        if (!empty($comment->comment_post_ID) && $isCommentStatusChanged) {
            $cacheTags = [
                ...$cacheTags,
                ...CoreTags::posts($comment->comment_post_ID),
            ];
        }

        $this->cacheTags->clear($cacheTags);
    }

    /**
     * This hook is misleading but it runs for scheduled posts, editing a
     * published post as well as manually publishing/unpublishing a post.
     */
    public function onPostStatusTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if (!CoreTags::isCacheablePostType($post->post_type)) {
            return;
        }

        $isStatusChanged = $newStatus !== $oldStatus;
        $cacheTags = [
            ...CoreTags::posts($post->ID),
        ];

        // If it's new or unpublished, clear the taxonomy pages and the archive page
        if ($isStatusChanged && ($newStatus === 'publish' || $oldStatus === 'publish')) {
            $taxonomies = array_intersect(
                CoreTags::getCacheableTaxonomies(),
                get_post_taxonomies()
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
     * When a term is added to an object, clear caches.
     */
    public function onTermSet(int $objectId, array $terms, array $taxonomyIds, string $taxonomy): void
    {
        if (!CoreTags::isCacheableTaxonomy($taxonomy)) {
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
    }

    /**
     * Whenever a post meta is updated, clear the cache of the post.
     */
    public function onPostMetaUpdate(int $metaId, int $objectId, string $metaKey): void
    {
        if (!CoreTags::isCacheablePostType($objectId)) {
            return;
        }
        if (wp_is_post_revision($objectId)) {
            return;
        }

        if (!CoreTags::isCacheablePostMeta($metaKey, $objectId)) {
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
        if (!CoreTags::isCacheableTaxonomy($taxonomy)) {
            return;
        }

        $cacheTags = [
            ...CoreTags::terms($termId),
            ...CoreTags::termPages($termId),
            ...CoreTags::anyTerm($taxonomy),
        ];

        // If it's a new term, clear taxonomy listings.
        if (!$updated) {
            $cacheTags = [
                ...CoreTags::taxonomy($taxonomy),
            ];
        }

        $this->cacheTags->clear($cacheTags);
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
}
