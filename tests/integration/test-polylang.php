<?php

use Genero\Sage\CacheTags\Actions\Polylang;
use Genero\Sage\CacheTags\CacheTags;
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
        $tags = $this->action()->filterArchiveTags(['archive:post', 'post:5']);

        $this->assertSame(['archive:post:en', 'post:5'], $tags);
    }

    public function test_passes_tags_through_without_a_language_context(): void
    {
        PLL()->curlang = null;

        $this->assertSame(['archive:post'], $this->action()->filterArchiveTags(['archive:post']));
    }

    public function test_tags_helper_returns_the_current_language(): void
    {
        $this->assertSame(['lang:en'], PolylangTags::language());
    }

    public function test_tags_helper_builds_a_per_language_archive(): void
    {
        $model = PLL()->model;
        if (! $model->get_language('fi')) {
            $model->add_language(['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'rtl' => 0, 'term_group' => 1, 'flag' => 'fi']);
            $model->clean_languages_cache();
        }

        $tags = PolylangTags::archiveAllLanguages('post');

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

        $this->assertContains('archive:post:en', $purge->getValue($cacheTags));
    }
}
