<?php

use Genero\Sage\CacheTags\Actions\Polylang;
use Genero\Sage\CacheTags\CacheTags;

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
}
