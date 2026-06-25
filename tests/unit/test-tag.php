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
        yield 'custom deep' => ['a:b:c:d:e'];
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
        $this->assertSame('taxonomy:category:any', (string) Tag::taxonomy('category')->any());
        $this->assertSame('role:editor', (string) Tag::role('editor'));
        $this->assertSame('option:blogname', (string) Tag::option('blogname'));
        $this->assertSame('lang:fi', (string) Tag::language('fi'));
        $this->assertSame('nonce', (string) Tag::nonce());
        $this->assertSame('site:1', (string) Tag::site(1));
        $this->assertSame('gform:5', (string) Tag::form(5));
        $this->assertSame('custom:9', (string) Tag::of('custom', 9));
    }

    public function test_qualify_is_the_general_trailing_variant(): void
    {
        // Replaces the i18n-specific inLanguage() — language is just a qualifier.
        $this->assertSame('archive:post:fi', (string) Tag::archive('post')->qualify('fi'));
        $this->assertSame('archive:post:any', (string) Tag::archive('post')->qualify('any'));
    }

    public function test_scope_is_general_and_composes_for_nested_dimensions(): void
    {
        // Replaces the site-only withScope(int): any dimension, any depth.
        $this->assertSame('site:7:post:123', (string) Tag::post(123)->scope('site', 7));
        $this->assertSame(
            'network:2:site:5:post:123',
            (string) Tag::post(123)->scope('network', 2)->scope('site', 5),
            'scopes nest outer→inner in call order'
        );
        $this->assertSame('site:7:custom:thing', (string) Tag::of('custom', 'thing')->scope('site', 7));
    }

    public function test_parse_extracts_structured_fields_and_scope(): void
    {
        $tag = Tag::parse('site:5:post:123');
        $this->assertSame('post', $tag->type);
        $this->assertSame(123, $tag->id);
        $this->assertSame([['site', 5]], $tag->scopes);
        $this->assertNull($tag->qualifier);

        $nested = Tag::parse('network:2:site:5:archive:post:any');
        $this->assertSame('archive', $nested->type);
        $this->assertSame('post', $nested->id);
        $this->assertSame('any', $nested->qualifier);
        $this->assertSame([['network', 2], ['site', 5]], $nested->scopes);
    }

    public function test_a_bare_scope_dimension_tag_is_not_mistaken_for_a_prefix(): void
    {
        // "site:5" is the site tag itself, not an empty-bodied scope.
        $tag = Tag::parse('site:5');
        $this->assertSame('site', $tag->type);
        $this->assertSame(5, $tag->id);
        $this->assertSame([], $tag->scopes);
    }

    public function test_from_accepts_strings_and_tags(): void
    {
        $tag = Tag::post(1);
        $this->assertSame($tag, Tag::from($tag), 'a Tag passes through');
        $this->assertSame('post:5', (string) Tag::from('post:5'), 'a string is parsed');
    }
}
