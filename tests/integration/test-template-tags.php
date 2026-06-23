<?php

use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * Front-end template tagging (Core::addTemplateCacheTags).
 *
 * @covers \Genero\Sage\CacheTags\Actions\Core
 */
class TestTemplateTags extends RestTestCase
{
    /** Tags added for the template that would render the given URL. */
    private function templateTags(string $url): array
    {
        $this->resetCacheTags();
        $this->go_to($url);
        (new Core($this->cacheTags))->addTemplateCacheTags();

        return $this->cacheTags->get();
    }

    public function test_search_results_are_tagged_as_a_post_archive(): void
    {
        $this->assertContains('archive:post', $this->templateTags(home_url('/?s=hello')));
    }

    public function test_date_archive_is_tagged(): void
    {
        self::factory()->post->create(['post_status' => 'publish', 'post_date' => '2020-01-15 12:00:00']);

        $this->assertContains('archive:post', $this->templateTags(home_url('/?year=2020&monthnum=1')));
    }

    public function test_attachment_page_is_tagged(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $attachmentId = self::factory()->attachment->create_object('image.jpg', $postId, [
            'post_mime_type' => 'image/jpeg',
        ]);

        $this->assertContains(
            "post:{$attachmentId}",
            $this->templateTags(home_url('/?attachment_id='.$attachmentId))
        );
    }
}
