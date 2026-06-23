<?php

use Genero\Sage\CacheTags\Actions\DebugComment;
use Genero\Sage\CacheTags\CacheTags;

/**
 * @covers \Genero\Sage\CacheTags\Actions\DebugComment
 */
class TestDebugComment extends WP_UnitTestCase
{
    public function test_prints_the_url_and_human_readable_tags(): void
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'cacheTags');
        $ref->setAccessible(true);
        $ref->setValue($cacheTags, []);

        $postId = self::factory()->post->create(['post_title' => 'Hello World', 'post_status' => 'publish']);
        $termId = self::factory()->category->create(['name' => 'News']);
        $cacheTags->add(["post:{$postId}", "term:{$termId}"]);

        ob_start();
        (new DebugComment($cacheTags))->printCacheTagsDebug();
        $output = ob_get_clean();

        $this->assertStringContainsString('sage-cachetags', $output);
        $this->assertStringContainsString("post:{$postId}", $output);
        $this->assertStringContainsString('Hello World', $output, 'post tag is labelled with its title');
        $this->assertStringContainsString('News', $output, 'term tag is labelled with its name');
    }
}
