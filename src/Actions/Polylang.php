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
    /**
     * Qualifier of the tag a page carries for each translation its language
     * switcher links to, cleared only when that translation's URL changes.
     */
    const URL_QUALIFIER = 'url';

    /**
     * Snapshots taken on edit_terms, compared on edited_term: a translated
     * term's link fields, or a translation group's members.
     *
     * @var array<int, array>
     */
    protected array $beforeEdit = [];

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
        // A language switcher changes when the translation group does (written
        // to the non-public post_translations / term_translations taxonomy,
        // which Core ignores) or a translation's URL does.
        \add_action('created_term', [$this, 'onTranslationGroupCreated'], 10, 3);
        \add_action('edit_terms', [$this, 'onTermEdit'], 10, 2);
        \add_action('edited_term', [$this, 'onTermEdited'], 10, 3);
        \add_action('delete_term', [$this, 'onTranslationGroupDeleted'], 10, 4);
        \add_action('post_updated', [$this, 'onPostUpdated'], 10, 3);
        // wp_publish_post() (scheduled posts) doesn't fire post_updated.
        \add_action('transition_post_status', [$this, 'onScheduledPublish'], 10, 3);
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
     * Tag a post or term page with the URL of each translation its language
     * switcher links to: `post:{id}:url` / `term:{id}:url`. These are cleared
     * only when that translation's URL changes, not on every save of it, so a
     * content edit of one translation doesn't purge the others.
     */
    public function addTranslationTags(): void
    {
        $object = \get_queried_object();

        if (is_singular() && $object instanceof WP_Post) {
            $translations = array_diff(pll_get_post_translations($object->ID), [$object->ID]);
            $this->cacheTags->add(array_map(fn ($id) => Tag::post((int) $id)->qualify(self::URL_QUALIFIER), array_values($translations)));
        } elseif ((is_category() || is_tag() || is_tax()) && $object instanceof WP_Term) {
            $translations = array_diff(pll_get_term_translations($object->term_id), [$object->term_id]);
            $this->cacheTags->add(array_map(fn ($id) => Tag::term((int) $id)->qualify(self::URL_QUALIFIER), array_values($translations)));
        }
    }

    /**
     * A translated post's title, slug, parent or status changed (Core's
     * linkFieldsChanged), so the switchers linking to it are stale.
     */
    public function onPostUpdated(int $postId, WP_Post $after, WP_Post $before): void
    {
        if (Core::linkFieldsChanged($after, $before)) {
            $this->clearPostUrl($after);
        }
    }

    public function onScheduledPublish(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($oldStatus === 'future' && $newStatus === 'publish') {
            $this->clearPostUrl($post);
        }
    }

    protected function clearPostUrl(WP_Post $post): void
    {
        if (CoreTags::isCacheablePostType($post->post_type) && pll_is_translated_post_type($post->post_type)) {
            $this->cacheTags->clear(Tag::post($post->ID)->qualify(self::URL_QUALIFIER));
        }
    }

    /**
     * Before wp_update_term(): snapshot a translated term's link fields, or a
     * translation group's members.
     */
    public function onTermEdit(int $termId, string $taxonomy): void
    {
        $term = get_term($termId, $taxonomy);
        if (! $term instanceof WP_Term) {
            return;
        }

        if ($this->isTranslationGroup($taxonomy)) {
            $this->beforeEdit[$termId] = $this->groupMembers($term);
        } elseif (CoreTags::isCacheableTaxonomy($taxonomy) && pll_is_translated_taxonomy($taxonomy)) {
            $this->beforeEdit[$termId] = Core::termLinkFields($term);
        }
    }

    public function onTermEdited(int $termId, int $termTaxonomyId, string $taxonomy): void
    {
        if (! array_key_exists($termId, $this->beforeEdit)) {
            return;
        }
        $before = $this->beforeEdit[$termId];
        unset($this->beforeEdit[$termId]);

        $term = get_term($termId, $taxonomy);
        if (! $term instanceof WP_Term) {
            return;
        }

        if ($this->isTranslationGroup($taxonomy)) {
            $this->clearGroupChange($taxonomy, $before, $this->groupMembers($term));
        } elseif ($before !== Core::termLinkFields($term)) {
            $this->cacheTags->clear(Tag::term($termId)->qualify(self::URL_QUALIFIER));
        }
    }

    public function onTranslationGroupCreated(int $termId, int $termTaxonomyId, string $taxonomy): void
    {
        $term = get_term($termId, $taxonomy);
        if ($this->isTranslationGroup($taxonomy) && $term instanceof WP_Term) {
            $this->clearGroupChange($taxonomy, [], $this->groupMembers($term));
        }
    }

    /**
     * @param  WP_Term|mixed  $deletedTerm
     */
    public function onTranslationGroupDeleted(int $termId, int $termTaxonomyId, string $taxonomy, $deletedTerm): void
    {
        if ($this->isTranslationGroup($taxonomy) && $deletedTerm instanceof WP_Term) {
            $this->clearGroupChange($taxonomy, $this->groupMembers($deletedTerm), []);
        }
    }

    /**
     * Polylang links and unlinks translations by rewriting the group term's
     * description (lang => id) — unlinking leaves the relationship in place, so
     * set_object_terms alone would miss it. When the group changes, every
     * member's switcher does: purge the pages of old and new members.
     *
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     */
    protected function clearGroupChange(string $taxonomy, array $before, array $after): void
    {
        ksort($before);
        ksort($after);
        if ($before === $after) {
            return;
        }

        $members = array_values(array_unique([...array_values($before), ...array_values($after)]));

        $this->cacheTags->clear($taxonomy === 'post_translations'
            ? CoreTags::posts($members)
            : CoreTags::termPages($members));
    }

    protected function isTranslationGroup(string $taxonomy): bool
    {
        return in_array($taxonomy, ['post_translations', 'term_translations'], true);
    }

    /**
     * @return array<string, int>
     */
    protected function groupMembers(WP_Term $group): array
    {
        $members = maybe_unserialize($group->description);
        if (! is_array($members)) {
            return [];
        }

        // The description can hold extra, non-translation data (see
        // PLL_Translated_Object::save_translations); keep language => id.
        return array_map('intval', array_filter($members, fn ($id, $lang) => is_string($lang) && is_numeric($id), ARRAY_FILTER_USE_BOTH));
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
