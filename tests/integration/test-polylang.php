<?php

use Genero\Sage\CacheTags\Actions\Polylang;
use Genero\Sage\CacheTags\CacheTags;

// Polylang isn't installed in the test environment — stub the functions the
// action calls, controllable per-test via globals (default: language inactive).
if (! function_exists('pll_current_language')) {
    function pll_current_language()
    {
        return $GLOBALS['__pll_lang'] ?? false;
    }
}
if (! function_exists('pll_is_translated_post_type')) {
    function pll_is_translated_post_type($postType)
    {
        return in_array($postType, $GLOBALS['__pll_translated'] ?? [], true);
    }
}
if (! function_exists('pll_get_post_language')) {
    function pll_get_post_language($id)
    {
        return $GLOBALS['__pll_post_lang'] ?? false;
    }
}

/**
 * @covers \Genero\Sage\CacheTags\Actions\Polylang
 */
class TestPolylang extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $GLOBALS['__pll_lang'] = false;
        $GLOBALS['__pll_translated'] = [];
        $GLOBALS['__pll_post_lang'] = false;
    }

    private function action(): Polylang
    {
        return new Polylang(CacheTags::getInstance());
    }

    public function test_suffixes_translated_archive_tags_with_the_language(): void
    {
        $GLOBALS['__pll_lang'] = 'en';
        $GLOBALS['__pll_translated'] = ['post'];

        $tags = $this->action()->filterArchiveTags(['archive:post', 'post:5']);

        // Archive tag gets the language; the per-post tag is left alone.
        $this->assertSame(['archive:post:en', 'post:5'], $tags);
    }

    public function test_passes_tags_through_without_a_language_context(): void
    {
        $GLOBALS['__pll_lang'] = false;

        $this->assertSame(['archive:post'], $this->action()->filterArchiveTags(['archive:post']));
    }

    public function test_only_translated_post_types_are_suffixed(): void
    {
        $GLOBALS['__pll_lang'] = 'en';
        $GLOBALS['__pll_translated'] = ['post']; // 'page' is not translated

        $this->assertSame(['archive:page'], $this->action()->filterArchiveTags(['archive:page']));
    }

    public function test_status_transition_of_an_untranslated_type_is_a_no_op(): void
    {
        $GLOBALS['__pll_lang'] = 'en';
        $GLOBALS['__pll_translated'] = []; // nothing translated
        $post = self::factory()->post->create_and_get(['post_status' => 'publish']);

        // Should simply return without error (no language-specific purge).
        $this->action()->onPostStatusTransition('publish', 'draft', $post);
        $this->assertTrue(true);
    }
}
