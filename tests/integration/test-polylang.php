<?php

use Genero\Sage\CacheTags\Actions\Polylang;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Tag;
use Genero\Sage\CacheTags\Tags\PolylangTags;

/**
 * Real integration against an installed Polylang: the action's language tagging
 * and archive-tag suffixing run through the actual pll_* functions.
 *
 * @covers \Genero\Sage\CacheTags\Actions\Polylang
 */
class TestPolylang extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        if (! function_exists('PLL') || ! PLL() || ! isset(PLL()->model)) {
            $this->markTestSkipped('Polylang is not installed in this environment.');
        }

        // Polylang's language terms / in-memory cache aren't reliably preserved
        // across the suite (DDL in other tests breaks transaction isolation), so
        // ensure the language exists for this test, then set the current one
        // (admin mode has no current language by default).
        $model = PLL()->model;
        $model->clean_languages_cache();
        if (! $model->get_language('en')) {
            $model->add_language(['name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'rtl' => 0, 'term_group' => 0, 'flag' => 'us']);
            $model->clean_languages_cache();
        }
        PLL()->curlang = $model->get_language('en');
    }

    private function action(): Polylang
    {
        return new Polylang(CacheTags::getInstance());
    }

    private function resetTags(): CacheTags
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'cacheTags');
        $ref->setAccessible(true);
        $ref->setValue($cacheTags, []);

        return $cacheTags;
    }

    public function test_adds_the_current_language_tag(): void
    {
        $cacheTags = $this->resetTags();

        $this->action()->addLanguageTag();

        $this->assertContains('lang:en', $cacheTags->get());
    }

    public function test_suffixes_translated_archive_tags_with_the_language(): void
    {
        // 'post' is a translated post type by default.
        $tags = Tag::toStrings($this->action()->filterArchiveTags(['archive:post', 'post:5']));

        $this->assertSame(['archive:post:en', 'post:5'], $tags);
    }

    public function test_expands_archive_tags_to_all_languages_without_a_context(): void
    {
        $model = PLL()->model;
        if (! $model->get_language('fi')) {
            $model->add_language(['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'rtl' => 0, 'term_group' => 1, 'flag' => 'fi']);
            $model->clean_languages_cache();
        }
        // No current language (admin/cron/WC/REST purge context).
        PLL()->curlang = null;

        $tags = Tag::toStrings($this->action()->filterArchiveTags(['archive:post', 'post:5']));

        // A bare archive tag must reach every language's stored listing.
        $this->assertContains('archive:post:en', $tags);
        $this->assertContains('archive:post:fi', $tags);
        $this->assertNotContains('archive:post', $tags, 'the bare tag is expanded, not kept');
        $this->assertContains('post:5', $tags, 'non-archive tags pass through');
    }

    public function test_tags_helper_returns_the_current_language(): void
    {
        $this->assertSame(['lang:en'], Tag::toStrings(PolylangTags::language()));
    }

    public function test_tags_helper_builds_a_per_language_archive(): void
    {
        $model = PLL()->model;
        if (! $model->get_language('fi')) {
            $model->add_language(['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'rtl' => 0, 'term_group' => 1, 'flag' => 'fi']);
            $model->clean_languages_cache();
        }

        $tags = Tag::toStrings(PolylangTags::archiveAllLanguages('post'));

        // 'post' is translated, so it expands to one archive tag per language.
        $this->assertContains('archive:post:en', $tags);
        $this->assertContains('archive:post:fi', $tags);
    }

    public function test_init_registers_the_polylang_hooks(): void
    {
        $action = $this->action();
        $action->init();

        $this->assertNotFalse(has_filter(CacheTags::FILTER_TAGS, [$action, 'filterArchiveTags']));
        $this->assertNotFalse(has_action('transition_post_status', [$action, 'onPostStatusTransition']));
        $this->assertNotFalse(has_action('template_redirect', [$action, 'addLanguageTag']));
    }

    public function test_add_language_tag_rest_tags_the_response_with_the_language(): void
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'cacheTags');
        $ref->setAccessible(true);
        $ref->setValue($cacheTags, []);

        $response = new WP_REST_Response(['ok' => true]);
        $result = $this->action()->addLanguageTagRest($response);

        $this->assertSame($response, $result);
        $this->assertContains('lang:en', $cacheTags->get());
    }

    public function test_on_post_status_transition_purges_the_language_specific_archive(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'draft']);
        pll_set_post_language($postId, 'en');

        $cacheTags = CacheTags::getInstance();
        $purge = new ReflectionProperty($cacheTags, 'purgeTags');
        $purge->setAccessible(true);
        $purge->setValue($cacheTags, []);

        $this->action()->onPostStatusTransition('publish', 'draft', get_post($postId));

        $this->assertContains('archive:post:en', Tag::toStrings($purge->getValue($cacheTags)));
    }

    // H2: a permanent delete never passes through transition_post_status, so the
    // language archive would otherwise be left stale.
    public function test_deleting_a_translated_post_purges_its_language_archive(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        pll_set_post_language($postId, 'en');

        $cacheTags = CacheTags::getInstance();
        $purge = new ReflectionProperty($cacheTags, 'purgeTags');
        $purge->setAccessible(true);
        $purge->setValue($cacheTags, []);

        $this->action()->onPostDelete($postId);

        $this->assertContains('archive:post:en', Tag::toStrings($purge->getValue($cacheTags)));
    }

    private function ensureFinnish(): void
    {
        $model = PLL()->model;
        if (! $model->get_language('fi')) {
            $model->add_language(['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'rtl' => 0, 'term_group' => 1, 'flag' => 'fi']);
            $model->clean_languages_cache();
        }
    }

    /**
     * @return array{0: int, 1: int} English and Finnish post ids, linked unless $link is false.
     */
    private function translatedPosts(bool $link = true): array
    {
        $this->ensureFinnish();
        $en = self::factory()->post->create(['post_status' => 'publish']);
        $fi = self::factory()->post->create(['post_status' => 'publish']);
        pll_set_post_language($en, 'en');
        pll_set_post_language($fi, 'fi');
        if ($link) {
            pll_save_post_translations(['en' => $en, 'fi' => $fi]);
        }

        return [$en, $fi];
    }

    /**
     * @return array{0: int, 1: int} English and Finnish category ids, linked.
     */
    private function translatedTerms(): array
    {
        $this->ensureFinnish();
        $en = self::factory()->category->create();
        $fi = self::factory()->category->create();
        pll_set_term_language($en, 'en');
        pll_set_term_language($fi, 'fi');
        pll_save_term_translations(['en' => $en, 'fi' => $fi]);

        return [$en, $fi];
    }

    private function queuedPurgeTags(): array
    {
        $cacheTags = CacheTags::getInstance();
        $purge = new ReflectionProperty($cacheTags, 'purgeTags');
        $purge->setAccessible(true);

        return Tag::toStrings($purge->getValue($cacheTags));
    }

    private function resetPurgeTags(): void
    {
        $cacheTags = CacheTags::getInstance();
        $purge = new ReflectionProperty($cacheTags, 'purgeTags');
        $purge->setAccessible(true);
        $purge->setValue($cacheTags, []);
    }

    // Linking translations rewrites the non-public post_translations group,
    // which Core ignores; both switchers change.
    public function test_linking_translations_purges_both_pages(): void
    {
        [$en, $fi] = $this->translatedPosts(false);
        $this->action()->init();
        $this->resetPurgeTags();

        pll_save_post_translations(['en' => $en, 'fi' => $fi]);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("post:{$en}", $tags);
        $this->assertContains("post:{$fi}", $tags);
    }

    // Unlinking only rewrites the group's description; the relationship stays.
    public function test_unlinking_a_translation_purges_both_pages(): void
    {
        [$en, $fi] = $this->translatedPosts();
        $this->action()->init();
        $this->resetPurgeTags();

        pll_save_post_translations(['en' => $en]);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("post:{$en}", $tags);
        $this->assertContains("post:{$fi}", $tags);
    }

    public function test_linking_term_translations_purges_both_term_pages(): void
    {
        $this->ensureFinnish();
        $en = self::factory()->category->create();
        $fi = self::factory()->category->create();
        pll_set_term_language($en, 'en');
        pll_set_term_language($fi, 'fi');
        $this->action()->init();
        $this->resetPurgeTags();

        pll_save_term_translations(['en' => $en, 'fi' => $fi]);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("term:{$en}:full", $tags);
        $this->assertContains("term:{$fi}:full", $tags);
    }

    // A content edit of one translation must not purge the others.
    public function test_a_content_only_save_queues_no_translation_purge(): void
    {
        [$en, $fi] = $this->translatedPosts();
        $this->action()->init();
        $this->resetPurgeTags();

        wp_update_post(['ID' => $en, 'post_content' => 'New body']);

        $tags = $this->queuedPurgeTags();
        $this->assertNotContains("post:{$en}:url", $tags);
        $this->assertNotContains("post:{$fi}", $tags);
        $this->assertNotContains("post:{$fi}:url", $tags);
    }

    public function test_changing_a_translations_url_purges_the_pages_linking_to_it(): void
    {
        [$en] = $this->translatedPosts();
        $this->action()->init();
        $this->resetPurgeTags();

        wp_update_post(['ID' => $en, 'post_name' => 'renamed-slug']);
        $this->assertContains("post:{$en}:url", $this->queuedPurgeTags(), 'slug');

        $this->resetPurgeTags();
        wp_update_post(['ID' => $en, 'post_status' => 'draft']);
        $this->assertContains("post:{$en}:url", $this->queuedPurgeTags(), 'unpublished');
    }

    // wp_publish_post() fires transition_post_status but never post_updated.
    public function test_publishing_a_scheduled_translation_purges_the_pages_linking_to_it(): void
    {
        [$en] = $this->translatedPosts();
        wp_update_post(['ID' => $en, 'post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]);
        $this->action()->init();
        $this->resetPurgeTags();

        wp_publish_post($en);

        $this->assertContains("post:{$en}:url", $this->queuedPurgeTags());
    }

    public function test_changing_a_term_translations_slug_purges_the_pages_linking_to_it(): void
    {
        [$en] = $this->translatedTerms();
        $this->action()->init();
        $this->resetPurgeTags();

        wp_update_term($en, 'category', ['description' => 'Only the description']);
        $this->assertNotContains("term:{$en}:url", $this->queuedPurgeTags(), 'description');

        wp_update_term($en, 'category', ['slug' => 'renamed-term']);
        $this->assertContains("term:{$en}:url", $this->queuedPurgeTags(), 'slug');
    }

    /**
     * Save menu locations the way Appearance → Menus → Manage Locations does,
     * through Polylang's own handler: it moves `{location}___{lang}` out of the
     * theme mod into its nav_menus options (written on shutdown in 3.7+).
     *
     * @param  array<string, int>  $locations
     */
    private function saveLocationsScreen(array $locations): void
    {
        $get = $_GET;
        $request = $_REQUEST;
        try {
            $_GET['action'] = 'locations';
            $_REQUEST['_wpnonce'] = wp_create_nonce('save-menu-locations');
            set_theme_mod('nav_menu_locations', $locations);
        } finally {
            $_GET = $get;
            $_REQUEST = $request;
        }
    }

    // Polylang keeps non-default languages' locations out of the theme mod Core
    // diffs, and 3.7+ saves them after the shutdown purge has run.
    public function test_reassigning_a_languages_menu_location_through_polylang_clears_it(): void
    {
        if (! class_exists('PLL_Admin_Nav_Menu')) {
            $this->markTestSkipped('Polylang has no admin nav menu integration here.');
        }
        $this->ensureFinnish();
        register_nav_menu('primary', 'Primary');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $polylang = PLL();
        (new PLL_Admin_Nav_Menu($polylang))->admin_init();
        $this->action()->init();
        [$en, $fi, $other] = [wp_create_nav_menu('EN menu'), wp_create_nav_menu('FI menu'), wp_create_nav_menu('Other FI menu')];
        $this->saveLocationsScreen(['primary' => $en, 'primary___fi' => $fi]);
        $this->resetPurgeTags();

        // Only the Finnish menu changes; the theme mod itself keeps primary => $en.
        $this->saveLocationsScreen(['primary' => $en, 'primary___fi' => $other]);

        $this->assertContains('menu-location:primary', $this->queuedPurgeTags());

        $this->resetPurgeTags();
        $this->saveLocationsScreen(['primary' => $en, 'primary___fi' => $other]);
        $this->assertNotContains('menu-location:primary', $this->queuedPurgeTags(), 'nothing changed');
    }

    private function templatePartKey(string $slug, ?string $language = null): string
    {
        return 'template-part:'.get_stylesheet()."//{$slug}".($language ? ":{$language}" : '');
    }

    private function templatePartPost(string $slug): int
    {
        $postId = wp_insert_post([
            'post_type' => 'wp_template_part',
            'post_status' => 'publish',
            'post_name' => $slug,
            'post_title' => $slug,
        ]);
        wp_set_object_terms($postId, get_stylesheet(), 'wp_theme');

        return $postId;
    }

    // Polylang Pro resolves footer___fi on Finnish pages, falling back to the
    // default language's footer, so the page's key carries its language.
    public function test_a_template_part_is_tagged_with_the_pages_language(): void
    {
        $this->ensureFinnish();
        $this->action()->init();
        PLL()->curlang = PLL()->model->get_language('fi');
        $cacheTags = $this->resetTags();

        do_blocks('<!-- wp:template-part {"slug":"footer"} /-->');

        $tags = $cacheTags->get();
        $this->assertContains($this->templatePartKey('footer', 'fi'), $tags);
        $this->assertNotContains($this->templatePartKey('footer'), $tags);
    }

    public function test_saving_a_translated_template_part_purges_only_its_language(): void
    {
        $this->ensureFinnish();
        $this->action()->init();
        $this->resetPurgeTags();

        $postId = $this->templatePartPost('footer___fi');

        $tags = $this->queuedPurgeTags();
        $this->assertContains($this->templatePartKey('footer', 'fi'), $tags, 'adding a translation');
        $this->assertNotContains($this->templatePartKey('footer', 'en'), $tags);

        $this->resetPurgeTags();
        wp_delete_post($postId, true);
        $this->assertContains($this->templatePartKey('footer', 'fi'), $this->queuedPurgeTags(), 'removing it falls back to the default');

        // Trashing renames the post to header___fi__trashed first.
        $postId = $this->templatePartPost('header___fi');
        $this->resetPurgeTags();
        wp_trash_post($postId);
        $this->assertContains($this->templatePartKey('header', 'fi'), $this->queuedPurgeTags(), 'trashing a translation');
    }

    public function test_saving_the_default_template_part_purges_languages_falling_back_to_it(): void
    {
        $this->ensureFinnish();
        $this->action()->init();
        $this->resetPurgeTags();

        $this->templatePartPost('footer');

        $tags = $this->queuedPurgeTags();
        $this->assertContains($this->templatePartKey('footer', 'en'), $tags, 'the default language');
        $this->assertContains($this->templatePartKey('footer', 'fi'), $tags, 'fi has no translation, so it falls back');
    }

    public function test_saving_the_default_template_part_leaves_translated_languages(): void
    {
        $this->ensureFinnish();
        $this->templatePartPost('footer___fi');
        $this->action()->init();
        $this->resetPurgeTags();

        $this->templatePartPost('footer');

        $tags = $this->queuedPurgeTags();
        $this->assertContains($this->templatePartKey('footer', 'en'), $tags);
        $this->assertNotContains($this->templatePartKey('footer', 'fi'), $tags, 'fi renders its own translation');
    }

    // Core resolves the changed term_taxonomy_ids with get_terms(), which
    // Polylang filters by the current language unless told not to.
    public function test_setting_another_languages_term_purges_its_archive(): void
    {
        $this->ensureFinnish();
        $fiTerm = self::factory()->category->create();
        pll_set_term_language($fiTerm, 'fi');
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        // A Finnish post, so Polylang keeps the Finnish term on it.
        pll_set_post_language($postId, 'fi');
        PLL()->curlang = PLL()->model->get_language('en');
        $this->resetPurgeTags();

        wp_set_object_terms($postId, [$fiTerm], 'category');

        $this->assertContains("term:{$fiTerm}:full", $this->queuedPurgeTags());
    }

    public function test_a_singular_page_is_tagged_with_its_translations_urls(): void
    {
        [$en, $fi] = $this->translatedPosts();
        $this->go_to(add_query_arg('p', $en, home_url('/')));
        $cacheTags = $this->resetTags();

        $this->action()->addTranslationTags();

        $tags = $cacheTags->get();
        $this->assertContains("post:{$fi}:url", $tags);
        $this->assertNotContains("post:{$fi}", $tags, 'a content edit of the translation must not purge this page');
    }

    public function test_a_term_archive_is_tagged_with_its_translations_urls(): void
    {
        [$en, $fi] = $this->translatedTerms();
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        wp_set_object_terms($postId, [$en], 'category');
        $this->go_to(add_query_arg('cat', $en, home_url('/')));
        $cacheTags = $this->resetTags();

        $this->action()->addTranslationTags();

        $tags = $cacheTags->get();
        $this->assertContains("term:{$fi}:url", $tags);
        $this->assertNotContains("term:{$fi}", $tags);
    }

    // H2: the lang: tag must be registered before the priority-10 save/header
    // consumers (RestApi uses 9 for the same reason).
    public function test_rest_language_tag_is_registered_before_the_save_consumers(): void
    {
        $action = $this->action();
        $action->init();

        $this->assertSame(9, has_filter('rest_post_dispatch', [$action, 'addLanguageTagRest']));
    }
}
