<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tag;
use Genero\Sage\CacheTags\Tags\CoreTags;
use Genero\Sage\CacheTags\Tags\PolylangTags;
use WP_Post;
use WP_Term;

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
        // before_delete_post: a permanent delete never passes through
        // transition_post_status, so the language archive would otherwise be
        // left stale.
        \add_action('before_delete_post', [$this, 'onPostDelete'], 9);
        \add_action('template_redirect', [$this, 'addLanguageTag']);
        \add_action('template_redirect', [$this, 'addTranslationTags']);
        // Linking translations writes the non-public post_translations /
        // term_translations taxonomy, which Core ignores.
        \add_action('pll_save_post', [$this, 'onSavePost'], 10, 3);
        \add_action('pll_save_term', [$this, 'onSaveTerm'], 10, 3);
        // Priority 9 (not the default 10) so the lang: tag is added before
        // Bootstrap::saveCacheTagsRest and HttpHeader::restPostDispatch consume
        // the tag set at priority 10 — matching RestApi::tagResponse.
        \add_filter('rest_post_dispatch', [$this, 'addLanguageTagRest'], 9);
    }

    /**
     * Add the current language as a cache tag to every page.
     */
    public function addLanguageTag(): void
    {
        $this->cacheTags->add(PolylangTags::language());
    }

    /**
     * Tag a post or term page with its translations, which its language
     * switcher links to, so a translation's slug change or unpublish purges it.
     *
     * Post and term tags aren't language-qualified (filterArchiveTags only
     * qualifies archive tags), so these match what a translation's save clears.
     */
    public function addTranslationTags(): void
    {
        $object = \get_queried_object();

        if (is_singular() && $object instanceof WP_Post) {
            $translations = array_diff(pll_get_post_translations($object->ID), [$object->ID]);
            $this->cacheTags->add(CoreTags::posts(array_values($translations)));
        } elseif ((is_category() || is_tag() || is_tax()) && $object instanceof WP_Term) {
            $translations = array_diff(pll_get_term_translations($object->term_id), [$object->term_id]);
            $this->cacheTags->add(CoreTags::terms(array_values($translations)));
        }
    }

    /**
     * A post's translations link to it from their language switchers. Saving
     * it (which is also when translations are linked or unlinked) clears them,
     * as a newly linked translation's page doesn't carry this post's tag yet.
     *
     * @param  array<string, int>  $translations  Language slug => post id, including this post.
     */
    public function onSavePost(int $postId, WP_Post $post, array $translations): void
    {
        if (! CoreTags::isCacheablePostType($post->post_type)) {
            return;
        }

        $this->cacheTags->clear(CoreTags::posts(array_values(array_diff($translations, [$postId]))));
    }

    /**
     * The term equivalent of onSavePost().
     *
     * @param  array<string, int>  $translations  Language slug => term id, including this term.
     */
    public function onSaveTerm(int $termId, string $taxonomy, array $translations): void
    {
        if (! CoreTags::isCacheableTaxonomy($taxonomy)) {
            return;
        }

        $this->cacheTags->clear(CoreTags::terms(array_values(array_diff($translations, [$termId]))));
    }

    /**
     * Add the current language as a cache tag to REST responses.
     */
    public function addLanguageTagRest(\WP_REST_Response $response): \WP_REST_Response
    {
        $this->cacheTags->add(PolylangTags::language());

        return $response;
    }

    /**
     * Make archive tags for translated post types language-specific, so a purge
     * matches the per-language listing that was actually stored.
     *
     * Render/store side: a language context is set, so `archive:{type}` becomes
     * `archive:{type}:{currentLang}` — the single variant this page is stored
     * under. Purge side: there is usually NO language context (admin, cron,
     * WooCommerce stock/price hooks, REST), so a bare `archive:{type}` is
     * expanded to EVERY language's variant. Without this, a bare purge tag would
     * match none of the stored `archive:{type}:{lang}` entries and the
     * translated listings would never clear (the herrfors alert-banner bug).
     *
     * @param  array<string|Tag>  $tags
     * @return Tag[]
     */
    public function filterArchiveTags(array $tags): array
    {
        $lang = pll_current_language();
        $languages = $lang ? [$lang] : (function_exists('pll_languages_list') ? pll_languages_list() : []);

        $result = [];
        foreach ($tags as $tag) {
            $parsed = Tag::from($tag);
            // Only a bare archive:{type} (no language/any qualifier, no site
            // scope) — matching the previous count===2 string check exactly.
            $isBareTranslatedArchive = $parsed->type === 'archive'
                && $parsed->qualifier === null
                && $parsed->scopes === []
                && pll_is_translated_post_type($parsed->id);

            if ($isBareTranslatedArchive && $languages) {
                foreach ($languages as $language) {
                    $result[] = $parsed->qualify($language);
                }

                continue;
            }

            $result[] = $parsed;
        }

        return $result;
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

        $this->clearLanguageArchives($post);
    }

    /**
     * A permanently deleted translated post must purge its language archive too.
     */
    public function onPostDelete(int $postId): void
    {
        $post = get_post($postId);
        if ($post instanceof WP_Post) {
            $this->clearLanguageArchives($post);
        }
    }

    /**
     * Purge the language-specific archive(s) for a translated post. When the
     * post's language isn't assigned yet (a new post can transition to publish
     * before save_post sets its language), clear every language's archive so the
     * eventual language isn't missed.
     */
    protected function clearLanguageArchives(WP_Post $post): void
    {
        if (! CoreTags::isCacheablePostType($post->post_type) || ! pll_is_translated_post_type($post->post_type)) {
            return;
        }

        $lang = pll_get_post_language($post->ID);
        $languages = $lang ? [$lang] : (function_exists('pll_languages_list') ? pll_languages_list() : []);

        foreach ($languages as $language) {
            $this->cacheTags->clear(Tag::archive($post->post_type)->qualify($language));
        }
    }
}
