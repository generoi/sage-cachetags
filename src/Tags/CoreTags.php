<?php

namespace Genero\Sage\CacheTags\Tags;

use Exception;
use Illuminate\Support\Arr;
use WP_Comment;
use WP_Post;
use WP_Query;
use WP_Term;
use WP_User;

class CoreTags
{
    /**
     * Return cache tags for one or multiple posts.
     *
     * @param mixed $posts
     */
    public static function posts($posts = null): array
    {
        if (is_numeric($posts) || $posts instanceof WP_Post) {
            $posts = [$posts];
        }

        if (is_array($posts)) {
            return collect($posts)
                // Pluck the IDs if it's a list of objects.
                ->map(fn ($post) => $post instanceof WP_Post ? $post->ID : $post)
                ->map(fn ($postId) => ["post:$postId"])
                ->flatten()
                ->all();
        }

        return [];
    }

    /**
     * Return cache tags for one or multiple terms.
     *
     * @param mixed $terms
     */
    public static function terms($terms = null): array
    {
        if (is_numeric($terms) || $terms instanceof WP_Term) {
            $terms = [$terms];
        }

        if (is_array($terms)) {
            return collect($terms)
                // Pluck the IDs if it's a list of objects.
                ->map(fn ($term) => $term instanceof WP_Term ? $term->term_id : $term)
                ->map(fn ($termId) => ["term:$termId"])
                ->flatten()
                ->all();
        }

        return [];
    }

    /**
     * Return cache tags for one or multiple term pages.
     *
     * @param mixed $terms
     */
    public static function termPages($terms = null): array
    {
        return collect(self::terms($terms))
            ->map(fn ($tag) => "$tag:full")
            ->all();
    }

    /**
     * Return cache tags for one or multiple users.
     *
     * @param mixed $users
     */
    public static function users($users = null): array
    {
        if (is_numeric($users) || $users instanceof WP_User) {
            $users = [$users];
        }

        if (is_array($users)) {
            return collect($users)
                // Pluck the IDs if it's a list of objects.
                ->map(fn ($user) => $user instanceof WP_User ? $user->ID : $user)
                ->map(fn ($userId) => ["user:$userId"])
                ->flatten()
                ->all();
        }

        return [];
    }

    /**
     * Return cache tags for one or multiple comments.
     *
     * @param mixed $comments
     */
    public static function comments($comments = null): array
    {
        if (is_numeric($comments) || $comments instanceof WP_Comment) {
            $comments = [$comments];
        }

        if (is_array($comments)) {
            return collect($comments)
                // Pluck the IDs if it's a list of objects.
                ->map(fn ($comment) => $comment instanceof WP_Comment ? $comment->comment_ID : $comment)
                ->map(fn ($commentId) => ["comment:$commentId"])
                ->flatten()
                ->all();
        }

        return [];
    }

    /**
     * Return cache tags for the menu in a navigation location.
     *
     * @param string $location
     */
    public static function navigation(string $location = null): array
    {
        $menu = Arr::get(get_nav_menu_locations(), $location);
        if (!$menu) {
            // @TODO: wp normally picks the first menu
            return [];
        }
        return self::menu($menu);
    }

    /**
     * Return cache tags for a menu.
     *
     * @param int|string $menuId
     */
    public static function menu($menuId = null): array
    {
        if (is_string($menuId)) {
            $menuId = get_term_by('slug', $menuId, 'nav_menu')->term_id ?? null;

            if (!$menuId) {
                throw new Exception();
            }
        }

        if (is_numeric($menuId)) {
            return ["menu:$menuId"];
        }

        return [];
    }

    /**
     * Return cache tags for the current queried object.
     */
    public static function queriedObject($object = null): array
    {
        if (is_null($object)) {
            $object = \get_queried_object();
        }

        if ($object instanceof WP_Post) {
            return self::posts($object);
        }
        if ($object instanceof WP_Term) {
            return [
                ...self::terms($object),
                ...self::termPages($object),
            ];
        }
        throw new Exception();
    }

    /**
     * Return cache tags for a WP_Query.
     *
     * @param WP_Query $query
     */
    public static function query(WP_Query $query): array
    {
        return collect($query->get_posts())
            ->pluck('ID')
            ->map(fn ($postId) => self::posts($postId))
            ->flatten()
            ->all();
    }

    /**
     * Return cache tags for one or many post type archives.
     *
     * @param mixed $postTypes
     */
    public static function archive($postTypes): array
    {
        if (is_string($postTypes) && $postTypes === 'any') {
            $postTypes = self::getCacheablePostTypes();
        }

        if (is_string($postTypes)) {
            $postTypes = [$postTypes];
        }

        if (is_array($postTypes)) {
            return collect($postTypes)
                ->map(fn ($postType) => sprintf('archive:%s', $postType))
                ->all();
        }
    }

    /**
     * Return cache tags for one or many taxonomy pages.
     *
     * @param mixed $taxonomies
     */
    public static function taxonomy($taxonomies): array
    {
        if (is_string($taxonomies) && $taxonomies === 'any') {
            $taxonomies = self::getCacheableTaxonomies();
        }

        if (is_string($taxonomies)) {
            $taxonomies = [$taxonomies];
        }

        if (is_array($taxonomies)) {
            return collect($taxonomies)
                ->map(fn ($taxonomy) => sprintf('taxonomy:%s', $taxonomy))
                ->all();
        }
    }

    public static function isCacheablePostType($postType): bool
    {
        if (is_numeric($postType)) {
            $postType = get_post_type($postType);
        } elseif ($postType instanceof WP_Post) {
            $postType = $postType->post_type;
        }

        return in_array($postType, self::getCacheablePostTypes());
    }

    public static function isCacheableTaxonomy($taxonomy): bool
    {
        if (is_numeric($taxonomy)) {
            $taxonomy = get_term($taxonomy)->taxonomy;
        } elseif ($taxonomy instanceof WP_Term) {
            $taxonomy = $taxonomy->taxonomy;
        }

        return in_array($taxonomy, self::getCacheableTaxonomies());
    }

    /**
     * Return all cacheable post types.
     */
    public static function getCacheablePostTypes(): array
    {
        return \get_post_types(['exclude_from_search' => false]);
    }

    /**
     * Return all cacheable taxonomies.
     */
    public static function getCacheableTaxonomies(): array
    {
        return \get_taxonomies(['public' => true]);
    }
}
