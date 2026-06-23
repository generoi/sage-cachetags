<?php

use Genero\Sage\CacheTags\Util;

/**
 * Tag validation/normalization rules.
 *
 * @covers \Genero\Sage\CacheTags\Util::normalizeTags
 * @covers \Genero\Sage\CacheTags\Util::isValidTag
 */
class TestTagNormalization extends WP_UnitTestCase
{
    public function test_keeps_well_formed_tags(): void
    {
        $tags = ['post:1', 'archive:post:any', 'term:5:full', 'taxonomy:category'];

        $this->assertSame($tags, Util::normalizeTags($tags));
    }

    public function test_drops_tags_with_whitespace_or_control_chars(): void
    {
        $tags = Util::normalizeTags(['post:1', 'role:Shop Manager', "post:\n2", 'term:3']);

        $this->assertSame(['post:1', 'term:3'], $tags);
    }

    public function test_drops_over_long_tags(): void
    {
        $tags = Util::normalizeTags(['post:1', 'x:'.str_repeat('a', 200)]);

        $this->assertSame(['post:1'], $tags);
    }

    public function test_flattens_and_dedupes(): void
    {
        $tags = Util::normalizeTags([['post:1', 'post:1'], 'post:2']);

        $this->assertSame(['post:1', 'post:2'], $tags);
    }
}
