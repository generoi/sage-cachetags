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
     * Build "prefix:id" tags for one or many entities given as an id, an object,
     * or an array of either. Returns [] for null/unrecognised input.
     *
     * @param  int|object|array<int|object>|null  $items
     * @param  class-string  $class
     * @param  callable(object):int  $idOf
     * @return string[]
     */
    private static function entityTags($items, string $prefix, string $class, callable $idOf): array
    {
        if (is_numeric($items) || $items instanceof $class) {
            $items = [$items];
        }

        if (! is_array($items)) {
            return [];
        }

        return array_map(
            fn ($item) => sprintf('%s:%d', $prefix, $item instanceof $class ? $idOf($item) : $item),
            $items
        );
    }

    /**
     * Return cache tags for one or multiple posts.
     *
     * @param  int|WP_Post|array<int|WP_Post>|null  $posts
     * @return string[]
     */
    public static function posts($posts = null): array
    {
        return self::entityTags($posts, 'post', WP_Post::class, fn (WP_Post $post) => $post->ID);
    }

    /**
     * Return cache tags for one or multiple terms.
     *
     * @param  int|WP_Term|array<int|WP_Term>|null  $terms
     * @return string[]
     */
    public static function terms($terms = null): array
    {
        return self::entityTags($terms, 'term', WP_Term::class, fn (WP_Term $term) => $term->term_id);
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
        return self::entityTags($users, 'user', WP_User::class, fn (WP_User $user) => $user->ID);
    }

    /**
     * Return cache tags for one or multiple comments.
     *
     * @param  int|WP_Comment|array<int|WP_Comment>|null  $comments
     * @return string[]
     */
    public static function comments($comments = null): array
    {
        return self::entityTags($comments, 'comment', WP_Comment::class, fn (WP_Comment $comment) => $comment->comment_ID);
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
    public static function menu($menu = null): array
    {
        if ($menu === null || $menu === '') {
            return [];
        }

        // wp_get_nav_menu_object resolves an id, slug, name, or menu object — all
        // the forms wp_nav_menu()'s `menu` arg accepts. The old code only handled
        // slugs, so a menu selected by name or object fataled. A non-empty value
        // that still can't resolve is a real error (typo / deleted menu).
        $object = wp_get_nav_menu_object($menu);
        if (! $object instanceof WP_Term) {
            throw new Exception('CoreTags::menu received an unknown menu: '.(is_scalar($menu) ? $menu : get_debug_type($menu)));
        }

        return ["menu:{$object->term_id}"];
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

        throw new Exception('CoreTags::queriedObject received an unexpected object: '.get_debug_type($object));
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
     * Build "prefix:name(:suffix)" tags for one or many items given as the
     * literal 'any' (expanded via $all), a name string, a WP object with a
     * ->name, or an array of those. Returns [] for unrecognised input.
     *
     * @param  string|object|array<string|object>  $items
     * @param  callable():string[]  $all  resolver for the 'any' keyword
     * @return string[]
     */
    private static function nameTags($items, string $prefix, callable $all, string $suffix = ''): array
    {
        if ($items === 'any') {
            $items = $all();
        }

        if (is_string($items) || is_object($items)) {
            $items = [$items];
        }

        if (! is_array($items)) {
            return [];
        }

        return array_map(
            fn ($item) => sprintf('%s:%s%s', $prefix, is_object($item) ? $item->name : $item, $suffix),
            $items
        );
    }

    /**
     * Return cache tags for one or many post type archives.
     *
     * @param  string|WP_Post_Type|array<string|WP_Post_Type>  $postTypes
     * @return string[]
     */
    public static function archive($postTypes): array
    {
        return self::nameTags($postTypes, 'archive', [self::class, 'getCacheablePostTypes']);
    }

    /**
     * Return cache tags for one or many taxonomy pages.
     *
     * @param  string|WP_Taxonomy|array<string|WP_Taxonomy>  $taxonomies
     * @return string[]
     */
    public static function taxonomy($taxonomies): array
    {
        return self::nameTags($taxonomies, 'taxonomy', [self::class, 'getCacheableTaxonomies']);
    }

    /**
     * Return cache tags for changes to any term in taxonomies.
     *
     * @param  string|WP_Taxonomy|array<string|WP_Taxonomy>  $taxonomies
     * @return string[]
     */
    public static function anyTerm($taxonomies): array
    {
        return self::nameTags($taxonomies, 'taxonomy', [self::class, 'getCacheableTaxonomies'], ':any');
    }

    /**
     * Return cache tags for changes to any post in post types.
     *
     * Unlike archive(), which tracks listing membership (publish/unpublish),
     * this is cleared on any change to any post of the type, and is used as the
     * coarse fallback when a response would otherwise emit too many post tags.
     *
     * @param  string|WP_Post_Type|array<string|WP_Post_Type>  $postTypes
     * @return string[]
     */
    public static function anyArchive($postTypes): array
    {
        return self::nameTags($postTypes, 'archive', [self::class, 'getCacheablePostTypes'], ':any');
    }

    /**
     * Return cache tags for changes to any user in roles.
     *
     * @param  string|WP_Role|array<string|WP_Role>  $roles
     * @return string[]
     */
    public static function anyUser($roles): array
    {
        return self::nameTags($roles, 'role', [self::class, 'getCacheableUserRoles']);
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
        // Public types are rendered on the front end; non-builtin types exposed
        // to REST are content served headlessly (e.g. public=false CPTs). Core's
        // internal REST types (wp_block, wp_template, …) are _builtin and stay
        // excluded. Sites tune the set via the filter.
        return \apply_filters(
            'cachetags/post_types',
            \get_post_types(['public' => true]) + \get_post_types(['_builtin' => false, 'show_in_rest' => true])
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
            \get_taxonomies(['public' => true]) + \get_taxonomies(['_builtin' => false, 'show_in_rest' => true])
        );
    }

    /**
     * Return all cacheable user roles.
     *
     * @return string[]
     */
    public static function getCacheableUserRoles(): array
    {
        // Role slugs, not display names — tags must be header-safe tokens, and
        // user mutations elsewhere clear role tags by slug.
        return array_keys(\wp_roles()->get_names());
    }

    /**
     * Return cache tags for one or multiple site options.
     *
     * @param  string|string[]  $options
     * @return string[]
     */
    public static function option($options): array
    {
        return array_map(
            fn ($option) => sprintf('option:%s', $option),
            is_array($options) ? $options : [$options]
        );
    }

    /**
     * Site options that map to an `option:` tag emitted by a block (site-title,
     * site-tagline, site-logo) and so should purge those pages when changed.
     *
     * Options not bound to a specific block are better handled with a full
     * flush than by tagging every page that might depend on them.
     *
     * @return string[]
     */
    public static function getCacheableOptions(): array
    {
        return \apply_filters('cachetags/options', [
            'blogname',
            'blogdescription',
            'site_logo',
        ]);
    }
}
