<?php

use Genero\Sage\CacheTags\Util;

/**
 * @covers \Genero\Sage\CacheTags\Util
 */
class TestUtil extends WP_UnitTestCase
{
    public function test_flatten_flattens_nested_arrays(): void
    {
        $this->assertSame(
            ['a', 'b', 'c', 'd'],
            Util::flatten(['a', ['b', ['c']], 'd'])
        );
    }

    public function test_current_url_is_the_trailingslashed_request_url(): void
    {
        global $wp;
        $wp->request = 'hello/world';

        $this->assertSame(home_url('/hello/world/'), Util::currentUrl());
    }

    public function test_env_reads_environment_variables(): void
    {
        putenv('CACHETAGS_TEST_ENV=present');

        $this->assertSame('present', Util::env('CACHETAGS_TEST_ENV'));
        $this->assertNull(Util::env('CACHETAGS_TEST_MISSING'));

        putenv('CACHETAGS_TEST_ENV');
    }

    public function test_chunk_request_splits_into_size_bounded_chunks(): void
    {
        $request = ['tag' => array_map(fn ($i) => "post:{$i}", range(1, 20))];
        $chunks = Util::chunkRequest($request, 40);

        $this->assertGreaterThan(1, count($chunks), 'splits when over the size limit');
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(40, strlen($chunk));
        }
    }

    public function test_chunk_request_keeps_a_small_request_in_one_chunk(): void
    {
        $chunks = Util::chunkRequest(['a' => '1', 'b' => '2'], 1000);

        $this->assertSame(['a=1&b=2'], $chunks);
    }
}
