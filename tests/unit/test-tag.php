<?php

use Genero\Sage\CacheTags\Tag;

/**
 * @covers \Genero\Sage\CacheTags\Tag
 */
class TestTag extends WP_UnitTestCase
{
    /** @return iterable<string, array{0: string}> */
    public function tagStrings(): iterable
    {
        // Every shape the codebase produces must round-trip through parse + cast.
        yield 'post' => ['post:123'];
        yield 'term' => ['term:9'];
        yield 'term full' => ['term:9:full'];
        yield 'user' => ['user:4'];
        yield 'comment' => ['comment:7'];
        yield 'menu' => ['menu:2'];
        yield 'gform' => ['gform:5'];
        yield 'site' => ['site:1'];
        yield 'archive' => ['archive:post'];
        yield 'archive any' => ['archive:product:any'];
        yield 'archive lang' => ['archive:post:fi'];
        yield 'taxonomy' => ['taxonomy:category'];
        yield 'taxonomy any' => ['taxonomy:category:any'];
        yield 'role' => ['role:editor'];
        yield 'option' => ['option:blogname'];
        yield 'lang' => ['lang:sv'];
        yield 'nonce' => ['nonce'];
        // Multisite "site:N:" scope prefixes.
        yield 'scoped post' => ['site:5:post:123'];
        yield 'scoped archive any' => ['site:5:archive:post:any'];
        yield 'scoped term full' => ['site:2:term:9:full'];
        // A site's own custom tags must survive verbatim.
        yield 'custom' => ['my:custom:tag'];
        yield 'custom single' => ['banner'];
        yield 'scoped custom' => ['site:3:my:custom:tag'];
    }

    /**
     * @dataProvider tagStrings
     */
    public function test_parse_and_cast_round_trip(string $string): void
    {
        $this->assertSame($string, (string) Tag::parse($string));
    }

    public function test_builders_serialize_to_the_expected_strings(): void
    {
        $this->assertSame('post:123', (string) Tag::post(123));
        $this->assertSame('term:9:full', (string) Tag::term(9)->full());
        $this->assertSame('archive:post', (string) Tag::archive('post'));
        $this->assertSame('archive:post:any', (string) Tag::archive('post')->any());
        $this->assertSame('archive:post:fi', (string) Tag::archive('post')->inLanguage('fi'));
        $this->assertSame('taxonomy:category:any', (string) Tag::taxonomy('category')->any());
        $this->assertSame('role:editor', (string) Tag::role('editor'));
        $this->assertSame('option:blogname', (string) Tag::option('blogname'));
        $this->assertSame('lang:fi', (string) Tag::language('fi'));
        $this->assertSame('nonce', (string) Tag::nonce());
        $this->assertSame('site:1', (string) Tag::site(1));
    }

    public function test_parse_extracts_structured_fields(): void
    {
        $tag = Tag::parse('site:5:post:123');
        $this->assertSame('post', $tag->type);
        $this->assertSame(123, $tag->identifier);
        $this->assertSame(5, $tag->scope);
        $this->assertNull($tag->qualifier);
        $this->assertFalse($tag->isRaw());

        $archive = Tag::parse('archive:product:any');
        $this->assertSame('archive', $archive->type);
        $this->assertSame('product', $archive->identifier);
        $this->assertSame('any', $archive->qualifier);
    }

    public function test_unknown_strings_are_raw_and_round_trip(): void
    {
        $tag = Tag::parse('my:custom:tag');
        $this->assertTrue($tag->isRaw());
        $this->assertSame('my:custom:tag', $tag->raw);
        $this->assertSame('my:custom:tag', (string) $tag);

        // A malformed known type is treated as raw, not silently coerced.
        $this->assertTrue(Tag::parse('post:not-a-number')->isRaw());
    }

    public function test_scope_applies_to_structured_and_raw_tags(): void
    {
        $this->assertSame('site:7:post:123', (string) Tag::post(123)->withScope(7));
        $this->assertSame('site:7:my:custom', (string) Tag::raw('my:custom')->withScope(7));
        // withScope(null) removes a scope.
        $this->assertSame('post:123', (string) Tag::parse('site:7:post:123')->withScope(null));
    }

    public function test_from_accepts_strings_and_tags(): void
    {
        $tag = Tag::post(1);
        $this->assertSame($tag, Tag::from($tag), 'a Tag passes through');
        $this->assertSame('post:5', (string) Tag::from('post:5'), 'a string is parsed');
    }
}
