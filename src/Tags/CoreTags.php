<?php

namespace Genero\Sage\CacheTags\Tags;

use Exception;
use Genero\Sage\CacheTags\Util;
use WP_Comment;
use WP_Post;
use WP_Post_Type;
use WP_Query;
use WP_Role;
use WP_Taxonomy;
use WP_Term;
use WP_User;

class CoreTags
{
    /**
     * Return cache tags for one or multiple posts.
     *
     * @param  int|WP_Post|array<int|WP_Post>|null  $posts
     * @return string[]
     */
    public static function posts($posts = null): array
    {
        if (is_numeric($posts) || $posts instanceof WP_Post) {
            $posts = [$posts];
        }

        if (is_array($posts)) {
            $tags = array_map(
                fn ($post) => [sprintf('post:%d', $post instanceof WP_Post ? $post->ID : $post)],
                $posts
            );

            return Util::flatten($tags);
        }

        return [];
    }

    /**
     * Return cache tags for one or multiple terms.
     *
     * @param  int|WP_Term|array<int|WP_Term>|null  $terms
     * @return string[]
     */
    public static function terms($terms = null): array
    {
        if (is_numeric($terms) || $terms instanceof WP_Term) {
            $terms = [$terms];
        }

        if (is_array($terms)) {
            $tags = array_map(
                fn ($term) => [sprintf('term:%d', $term instanceof WP_Term ? $term->term_id : $term)],
                $terms
            );

            return Util::flatten($tags);
        }

        return [];
    }

    /**
     * Return cache tags for one or multiple term pages.
     *
     * @param  int|WP_Term|array<int|WP_Term>|null  $terms
     * @return string[]
     */
    public static function termPages($terms = null): array
    {
        return array_map(fn ($tag) => "$tag:full", self::terms($terms));
    }

    /**
     * Return cache tags for one or multiple users.
     *
     * @param  int|WP_User|array<int|WP_User>|null  $users
     * @return string[]
     */
    public static function users($users = null): array
    {
        if (is_numeric($users) || $users instanceof WP_User) {
            $users = [$users];
        }

        if (is_array($users)) {
            $tags = array_map(
                fn ($user) => [sprintf('user:%d', $user instanceof WP_User ? $user->ID : $user)],
                $users
            );

            return Util::flatten($tags);
        }

        return [];
    }

    /**
     * Return cache tags for one or multiple comments.
     *
     * @param  int|WP_Comment|array<int|WP_Comment>|null  $comments
     * @return string[]
     */
    public static function comments($comments = null): array
    {
        if (is_numeric($comments) || $comments instanceof WP_Comment) {
            $comments = [$comments];
        }

        if (is_array($comments)) {
            $tags = array_map(
                fn ($comment) => [sprintf('comment:%d', $comment instanceof WP_Comment ? $comment->comment_ID : $comment)],
                $comments
            );

            return Util::flatten($tags);
        }

        return [];
    }

    /**
     * Return cache tags for the menu in a navigation location.
     *
     * @return string[]
     */
    public static function navigation(?string $location = null): array
    {
        if ($location === null) {
            return [];
        }

        $locations = get_nav_menu_locations();
        $menu = $locations[$location] ?? null;
        if (! $menu) {
            // @TODO: wp normally picks the first menu
            return [];
        }

        return self::menu($menu);
    }

    /**
     * Return cache tags for a menu.
     *
     * @return string[]
     */
    public static function menu(int|string|null $menuId = null): array
    {
        if (is_string($menuId)) {
            $menuId = get_term_by('slug', $menuId, 'nav_menu')->term_id ?? null;

            if (! $menuId) {
                throw new Exception;
            }
        }

        if (is_numeric($menuId)) {
            return ["menu:$menuId"];
        }

        return [];
    }

    /**
     * Return cache tags for the current queried object.
     *
     * @param  WP_Post|WP_Term|WP_Post_Type|null  $object
     * @return string[]
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
        if ($object instanceof WP_Post_Type) {
            return self::archive($object->name);
        }
        throw new Exception;
    }

    /**
     * Return cache tags for a WP_Query.
     *
     * @return string[]
     */
    public static function query(WP_Query $query): array
    {
        $tags = array_map(
            fn ($post) => self::posts($post->ID),
            $query->get_posts()
        );

        return Util::flatten($tags);
    }

    /**
     * Return cache tags for one or many post type archives.
     *
     * @param  string|WP_Post_Type|array<string|WP_Post_Type>  $postTypes
     * @return string[]
     */
    public static function archive($postTypes): array
    {
        if (is_string($postTypes) && $postTypes === 'any') {
            $postTypes = self::getCacheablePostTypes();
        }

        if ($postTypes instanceof WP_Post_Type) {
            $postTypes = [$postTypes];
        }

        if (is_string($postTypes)) {
            $postTypes = [$postTypes];
        }

        if (is_array($postTypes)) {
            return array_map(
                fn ($postType) => sprintf('archive:%s', $postType instanceof WP_Post_Type ? $postType->name : $postType),
                $postTypes
            );
        }

        return [];
    }

    /**
     * Return cache tags for one or many taxonomy pages.
     *
     * @param  string|WP_Taxonomy|array<string|WP_Taxonomy>  $taxonomies
     * @return string[]
     */
    public static function taxonomy($taxonomies): array
    {
        if (is_string($taxonomies) && $taxonomies === 'any') {
            $taxonomies = self::getCacheableTaxonomies();
        }

        if ($taxonomies instanceof WP_Taxonomy) {
            $taxonomies = [$taxonomies];
        }

        if (is_string($taxonomies)) {
            $taxonomies = [$taxonomies];
        }

        if (is_array($taxonomies)) {
            return array_map(
                fn ($taxonomy) => sprintf('taxonomy:%s', $taxonomy instanceof WP_Taxonomy ? $taxonomy->name : $taxonomy),
                $taxonomies
            );
        }

        return [];
    }

    /**
     * Return cache tags for changes to any term in taxonomies.
     *
     * @param  string|WP_Taxonomy|array<string|WP_Taxonomy>  $taxonomies
     * @return string[]
     */
    public static function anyTerm($taxonomies): array
    {
        if (is_string($taxonomies) && $taxonomies === 'any') {
            $taxonomies = self::getCacheableTaxonomies();
        }

        if ($taxonomies instanceof WP_Taxonomy) {
            $taxonomies = [$taxonomies];
        }

        if (is_string($taxonomies)) {
            $taxonomies = [$taxonomies];
        }

        if (is_array($taxonomies)) {
            return array_map(
                fn ($taxonomy) => sprintf('taxonomy:%s:any', $taxonomy instanceof WP_Taxonomy ? $taxonomy->name : $taxonomy),
                $taxonomies
            );
        }

        return [];
    }

    /**
     * Return cache tags for changes to any user in roles.
     *
     * @param  string|WP_Role|array<string|WP_Role>  $roles
     * @return string[]
     */
    public static function anyUser($roles): array
    {
        if (is_string($roles) && $roles === 'any') {
            $roles = self::getCacheableUserRoles();
        }

        if ($roles instanceof WP_Role) {
            $roles = [$roles];
        }

        if (is_string($roles)) {
            $roles = [$roles];
        }

        if (is_array($roles)) {
            return array_map(
                fn ($role) => sprintf('role:%s', $role instanceof WP_Role ? $role->name : $role),
                $roles
            );
        }

        return [];
    }

    public static function isCacheablePostMeta(string $metaKey, int $postId): bool
    {
        $value = true;
        if ($metaKey[0] === '_') {
            $value = false;
        }

        return apply_filters('cachetags/postmeta', $value, $metaKey, $postId);
    }

    /**
     * @param  int|WP_Post|WP_Post_Type|string  $postType
     */
    public static function isCacheablePostType($postType): bool
    {
        if ($postType instanceof WP_Post_Type) {
            $postType = $postType->name;
        } elseif (is_numeric($postType)) {
            $postType = get_post_type($postType);
        } elseif ($postType instanceof WP_Post) {
            $postType = $postType->post_type;
        }

        return in_array($postType, self::getCacheablePostTypes());
    }

    /**
     * @param  int|WP_Term|WP_Taxonomy|string  $taxonomy
     */
    public static function isCacheableTaxonomy($taxonomy): bool
    {
        if ($taxonomy instanceof WP_Taxonomy) {
            $taxonomy = $taxonomy->name;
        } elseif (is_numeric($taxonomy)) {
            $taxonomy = get_term($taxonomy)->taxonomy;
        } elseif ($taxonomy instanceof WP_Term) {
            $taxonomy = $taxonomy->taxonomy;
        }

        return in_array($taxonomy, self::getCacheableTaxonomies());
    }

    /**
     * Return all cacheable post types.
     *
     * @return string[]
     */
    public static function getCacheablePostTypes(): array
    {
        return \apply_filters(
            'cachetags/post_types',
            \get_post_types(['public' => true])
        );
    }

    /**
     * Return all cacheable taxonomies.
     *
     * @return string[]
     */
    public static function getCacheableTaxonomies(): array
    {
        return \apply_filters(
            'cachetags/taxonomies',
            \get_taxonomies(['public' => true])
        );
    }

    /**
     * Return all cacheable user roles.
     *
     * @return string[]
     */
    public static function getCacheableUserRoles(): array
    {
        return \wp_roles()->get_names();
    }
}
