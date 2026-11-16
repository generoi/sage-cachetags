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
     * Type of the tag a page carries for its translation group (the members
     * its language switcher links to): `translations:{group term id}`.
     */
    const GROUP_TAG = 'translations';

    /**
     * Separator of a translated template part's language suffix, as in
     * Polylang Pro's PLL_FSE_Template_Slug::SEPARATOR (footer___sv).
     */
    const TEMPLATE_SLUG_SEPARATOR = '___';

    /**
     * Snapshots taken on edit_terms, compared on edited_term: a translated
     * term's link fields, or a translation group's members.
     *
     * @var array<int, array>
     */
    protected array $beforeEdit = [];

    /**
     * Polylang's per-language menu locations before a theme-mods save.
     *
     * @var array<string, array<string, int>>|null
     */
    protected ?array $locationsBefore = null;

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
        $themeMods = 'pre_update_option_theme_mods_'.get_option('stylesheet');
        \add_filter($themeMods, [$this, 'snapshotMenuLocations'], 9);
        \add_filter($themeMods, [$this, 'compareMenuLocations'], 11);
        \add_filter('cachetags/block-template-tags', [$this, 'filterBlockTemplateTags'], 10, 3);
        \add_filter('cachetags/block-template-purge-tags', [$this, 'filterBlockTemplatePurgeTags'], 10, 4);
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
     * Tag a post or term page with its translation group,
     * `translations:{group term id}`: its language switcher links to every
     * member. One tag per page however many languages, cleared only when a
     * member's URL changes or the group does, so a content edit of one
     * translation doesn't purge the others.
     */
    public function addTranslationTags(): void
    {
        $object = \get_queried_object();

        if (is_singular() && $object instanceof WP_Post) {
            $this->cacheTags->add($this->groupTags($object->ID, 'post_translations'));
        } elseif ((is_category() || is_tag() || is_tax()) && $object instanceof WP_Term) {
            $this->cacheTags->add($this->groupTags($object->term_id, 'term_translations'));
        }
    }

    /**
     * A translated post's URL changed, so the switchers linking to it are
     * stale. Only the fields its permalink is built from count — not the title,
     * which a switcher doesn't show.
     */
    public function onPostUpdated(int $postId, WP_Post $after, WP_Post $before): void
    {
        $fields = ['post_name', 'post_parent', 'post_status'];
        if (preg_match('/%(year|monthnum|day|hour|minute|second)%/', (string) get_option('permalink_structure'))) {
            $fields[] = 'post_date';
        }

        foreach ($fields as $field) {
            if ($after->$field !== $before->$field) {
                $this->clearPostGroup($after);

                return;
            }
        }
    }

    /**
     * wp_publish_post() (scheduled posts) never fires post_updated.
     */
    public function onScheduledPublish(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($oldStatus === 'future' && $newStatus === 'publish') {
            $this->clearPostGroup($post);
        }
    }

    protected function clearPostGroup(WP_Post $post): void
    {
        if (CoreTags::isCacheablePostType($post->post_type) && pll_is_translated_post_type($post->post_type)) {
            $this->cacheTags->clear($this->groupTags($post->ID, 'post_translations'));
        }
    }

    /**
     * Before wp_update_term(): snapshot a translated term's URL fields (slug,
     * parent), or a translation group's members.
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
            $this->beforeEdit[$termId] = [$term->slug, (int) $term->parent];
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
            $this->clearGroupChange($taxonomy, $termId, $before, $this->groupMembers($term));
        } elseif ($before !== [$term->slug, (int) $term->parent]) {
            $this->cacheTags->clear($this->groupTags($termId, 'term_translations'));
        }
    }

    public function onTranslationGroupCreated(int $termId, int $termTaxonomyId, string $taxonomy): void
    {
        $term = get_term($termId, $taxonomy);
        if ($this->isTranslationGroup($taxonomy) && $term instanceof WP_Term) {
            $this->clearGroupChange($taxonomy, $termId, [], $this->groupMembers($term));
        }
    }

    /**
     * @param  WP_Term|mixed  $deletedTerm
     */
    public function onTranslationGroupDeleted(int $termId, int $termTaxonomyId, string $taxonomy, $deletedTerm): void
    {
        if ($this->isTranslationGroup($taxonomy) && $deletedTerm instanceof WP_Term) {
            $this->clearGroupChange($taxonomy, $termId, $this->groupMembers($deletedTerm), []);
        }
    }

    /**
     * Polylang links and unlinks translations by rewriting the group term's
     * description (lang => id) — unlinking leaves the relationship in place, so
     * set_object_terms alone would miss it. When the group changes, every
     * member's switcher does: the members still in it carry the group tag, and
     * the ones joining or leaving are purged by their own tags, since their
     * pages carry another group's tag or none.
     *
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     */
    protected function clearGroupChange(string $taxonomy, int $groupId, array $before, array $after): void
    {
        ksort($before);
        ksort($after);
        if ($before === $after) {
            return;
        }

        $changed = array_values(array_unique([
            ...array_diff($before, $after),
            ...array_diff($after, $before),
        ]));

        $this->cacheTags->clear([
            Tag::of(self::GROUP_TAG, $groupId),
            ...($taxonomy === 'post_translations' ? CoreTags::posts($changed) : CoreTags::termPages($changed)),
        ]);
    }

    /**
     * The `translations:{group}` tag of a post's or term's translation group.
     *
     * @return Tag[]
     */
    protected function groupTags(int $objectId, string $taxonomy): array
    {
        $groups = wp_get_object_terms($objectId, $taxonomy, ['fields' => 'ids']);

        return is_array($groups) && $groups ? [Tag::of(self::GROUP_TAG, (int) reset($groups))] : [];
    }

    /**
     * Before Polylang handles a nav_menu_locations save (its own
     * pre_update_option_theme_mods_{stylesheet} filter, priority 10), keep its
     * per-language locations to compare with afterwards.
     */
    public function snapshotMenuLocations($value)
    {
        $this->locationsBefore = $this->languageMenuLocations();

        return $value;
    }

    /**
     * Polylang keeps the non-default languages' locations out of the
     * nav_menu_locations theme mod (which Core diffs) and in its options, as
     * nav_menus[stylesheet][location][lang] => menu id. Polylang 3.7+ writes
     * that option only on shutdown at priority 1000, after the purge at
     * shutdown 10, so compare the in-memory options right after its filter ran.
     */
    public function compareMenuLocations($value)
    {
        $before = $this->locationsBefore ?? [];
        $after = $this->languageMenuLocations();
        $this->locationsBefore = null;

        $tags = [];
        foreach (array_keys($before + $after) as $location) {
            $old = array_map('intval', (array) ($before[$location] ?? []));
            $new = array_map('intval', (array) ($after[$location] ?? []));
            ksort($old);
            ksort($new);
            if (array_filter($old) !== array_filter($new)) {
                $tags[] = CoreTags::menuLocation((string) $location);
            }
        }
        $this->cacheTags->clear($tags);

        return $value;
    }

    /**
     * @return array<string, array<string, int>>
     */
    protected function languageMenuLocations(): array
    {
        $options = PLL()->options ?? null;
        if (! is_array($options) && ! $options instanceof \ArrayAccess) {
            return [];
        }

        $menus = $options['nav_menus'] ?? [];
        $theme = get_option('stylesheet');

        return is_array($menus[$theme] ?? null) ? $menus[$theme] : [];
    }

    /**
     * A template part renders per language: Polylang Pro stores a translation
     * as its own post, slug `{slug}___{lang}` (PLL_FSE_Template_Slug), and on a
     * non-default language page resolves `{slug}___{lang}` first, then the
     * default language's `{slug}`, then the theme file. So the page's tag is
     * qualified with its language.
     *
     * @param  Tag[]  $tags
     * @return Tag[]
     */
    public function filterBlockTemplateTags(array $tags, string $id, string $templateType): array
    {
        $language = pll_current_language();
        if ($templateType !== 'wp_template_part' || ! $language) {
            return $tags;
        }

        return array_map(fn ($tag) => Tag::from($tag)->qualify($language), $tags);
    }

    /**
     * Map a saved template part post to the languages that render it: a
     * `{slug}___{lang}` translation to that language; the default-language
     * `{slug}` to the default language and every language without a published
     * translation of its own (they fall back to it), plus the bare tag for a page
     * rendered without a language. Adding or removing a translation changes
     * what its language renders, and clears it the same way.
     *
     * @param  Tag[]  $tags
     * @return Tag[]
     */
    public function filterBlockTemplatePurgeTags(array $tags, WP_Post $post, string $theme, string $slug): array
    {
        $languages = function_exists('pll_languages_list') ? pll_languages_list() : [];
        if ($post->post_type !== 'wp_template_part' || ! $languages) {
            return $tags;
        }

        foreach ($languages as $language) {
            $suffix = self::TEMPLATE_SLUG_SEPARATOR.$language;
            if (str_ends_with($slug, $suffix) && strlen($slug) > strlen($suffix)) {
                return array_map(
                    fn ($tag) => $tag->qualify($language),
                    CoreTags::blockTemplate("{$theme}//".substr($slug, 0, -strlen($suffix)), 'wp_template_part')
                );
            }
        }

        $translated = $this->translatedTemplatePartSlugs($slug, $theme, $languages);
        $default = pll_default_language();
        $result = $tags;
        foreach ($languages as $language) {
            if ($language === $default || ! in_array($slug.self::TEMPLATE_SLUG_SEPARATOR.$language, $translated, true)) {
                foreach ($tags as $tag) {
                    $result[] = Tag::from($tag)->qualify($language);
                }
            }
        }

        return $result;
    }

    /**
     * The published `{slug}___{lang}` translations of a template part.
     *
     * @param  string[]  $languages
     * @return string[]
     */
    protected function translatedTemplatePartSlugs(string $slug, string $theme, array $languages): array
    {
        $query = new \WP_Query([
            'post_type' => 'wp_template_part',
            'post_status' => 'publish',
            'post_name__in' => array_map(fn ($language) => $slug.self::TEMPLATE_SLUG_SEPARATOR.$language, $languages),
            'tax_query' => [['taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => $theme]],
            // Every language's post, not only the current one's.
            'lang' => '',
            'posts_per_page' => count($languages),
            'no_found_rows' => true,
        ]);

        return array_map(fn (WP_Post $post) => $post->post_name, $query->posts);
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
