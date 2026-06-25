<?php

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Tag;

/**
 * The header-byte-budget collapse in CacheTags::get()/bound(): when the combined
 * tag header would exceed the limit, per-object post:/term: tags collapse to
 * their coarse archive:/taxonomy: "any" form rather than overflow.
 *
 * @covers \Genero\Sage\CacheTags\CacheTags
 */
class TestCacheTags extends WP_UnitTestCase
{
    private function resetTags(): CacheTags
    {
        $cacheTags = CacheTags::getInstance();
        foreach (['cacheTags', 'boundedTags'] as $property) {
            $ref = new ReflectionProperty($cacheTags, $property);
            $ref->setAccessible(true);
            $ref->setValue($cacheTags, $property === 'boundedTags' ? null : []);
        }

        return $cacheTags;
    }

    public function test_keeps_per_object_tags_within_the_budget(): void
    {
        $cacheTags = $this->resetTags();
        $id = self::factory()->post->create(['post_status' => 'publish']);
        $cacheTags->add(["post:{$id}"]);

        $this->assertContains("post:{$id}", $cacheTags->get());
    }

    public function test_collapses_post_tags_over_the_budget_to_the_archive(): void
    {
        $cacheTags = $this->resetTags();
        $ids = self::factory()->post->create_many(5, ['post_status' => 'publish']);
        $cacheTags->add(array_map(fn ($id) => "post:{$id}", $ids));
        // Big enough to hold the coarse collapse tag, smaller than the per-object set.
        add_filter('cachetags/max-header-bytes', fn () => 30);

        $tags = $cacheTags->get();

        $this->assertContains('archive:post:any', $tags);
        foreach ($ids as $id) {
            $this->assertNotContains("post:{$id}", $tags);
        }
    }

    public function test_collapses_term_tags_over_the_budget_to_the_taxonomy(): void
    {
        $cacheTags = $this->resetTags();
        $termIds = self::factory()->category->create_many(5);
        $cacheTags->add(array_map(fn ($id) => "term:{$id}", $termIds));
        // Big enough to hold the coarse collapse tag, smaller than the per-object set.
        add_filter('cachetags/max-header-bytes', fn () => 30);

        $this->assertContains('taxonomy:category:any', $cacheTags->get());
    }

    // A custom FILTER_TAGS filter must not be able to push a header-unsafe tag
    // (control chars) through to the Cache-Tag header.
    public function test_get_revalidates_filtered_tags_for_header_safety(): void
    {
        $cacheTags = $this->resetTags();
        $cacheTags->add(['post:1']);
        add_filter('cachetags/filter-tags', fn ($tags) => [...$tags, "evil\r\nX-Injected: 1"]);

        $tags = $cacheTags->get();

        $this->assertContains('post:1', $tags);
        $this->assertNotContains("evil\r\nX-Injected: 1", $tags);
    }

    // The structured Tag API and raw custom strings interoperate through add().
    public function test_accepts_tag_objects_alongside_custom_strings(): void
    {
        $cacheTags = $this->resetTags();
        $id = self::factory()->post->create(['post_status' => 'publish']);

        $cacheTags->add([Tag::post($id), 'banner', Tag::archive('post')->any()]);

        $tags = $cacheTags->get();
        $this->assertContains("post:{$id}", $tags, 'Tag object serialized');
        $this->assertContains('banner', $tags, 'custom raw string preserved verbatim');
        $this->assertContains('archive:post:any', $tags);
    }

    public function test_add_accepts_a_single_tag_multiple_args_or_an_array(): void
    {
        $cacheTags = $this->resetTags();

        $cacheTags->add(Tag::nonce());                            // single Tag
        $cacheTags->add('post:1', 'term:2');                      // variadic strings
        $cacheTags->add(['comment:3', Tag::archive('post')->any()]); // array (back-compat)

        $tags = $cacheTags->get();
        foreach (['nonce', 'post:1', 'term:2', 'comment:3', 'archive:post:any'] as $expected) {
            $this->assertContains($expected, $tags);
        }
    }

    // The Site action prefixes every tag with "site:N:"; the collapse must see
    // through the prefix and re-apply it, or the prefixed post:/term: tags would
    // be dropped instead of collapsed → stale.
    public function test_collapses_site_prefixed_tags_preserving_the_prefix(): void
    {
        $cacheTags = $this->resetTags();
        $ids = self::factory()->post->create_many(5, ['post_status' => 'publish']);
        $cacheTags->add(array_map(fn ($id) => "site:1:post:{$id}", $ids));
        add_filter('cachetags/max-header-bytes', fn () => 40);

        $tags = $cacheTags->get();

        $this->assertContains('site:1:archive:post:any', $tags);
        foreach ($ids as $id) {
            $this->assertNotContains("site:1:post:{$id}", $tags);
        }
    }
}
