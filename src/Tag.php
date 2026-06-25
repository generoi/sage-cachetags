<?php

namespace Genero\Sage\CacheTags;

/**
 * A cache tag as structured, immutable data with a fluent builder.
 *
 * The codebase passes Tags around, not strings; a tag is only turned into a
 * string at a true boundary (the Cache-Tag header, the store, an HTTP purge),
 * and a string only becomes a Tag when one comes in (a site's custom tag, the
 * cachetags/filter-tags filter). parse() and __toString() are the single place
 * that conversion happens.
 *
 * Context is expressed with two general operations rather than purpose-built
 * methods, so new dimensions (a multisite network, a tenant, …) need no new API:
 *
 *   Tag::post(5)                         // post:5
 *   Tag::archive('post')->any()          // archive:post:any
 *   Tag::term(9)->full()                 // term:9:full
 *   Tag::post(5)->scope('site', 5)       // site:5:post:5
 *   Tag::post(5)->scope('network', 2)->scope('site', 5)  // network:2:site:5:post:5
 *   Tag::archive('post')->qualify($lang) // archive:post:fi
 *   Tag::of('gform', 5)                  // an arbitrary type
 *
 * Unrecognised strings round-trip verbatim, so custom tags are fully supported.
 */
final class Tag implements \Stringable
{
    /**
     * Leading "dimension:value" pairs treated as scope when reading a string,
     * outermost first. Extend this as new scoping dimensions appear.
     */
    const SCOPE_DIMENSIONS = ['network', 'site'];

    /**
     * @param  list<array{0: string, 1: int|string}>  $scopes  outer → inner
     */
    private function __construct(
        public readonly string $type,
        public readonly int|string|null $id = null,
        public readonly ?string $qualifier = null,
        public readonly array $scopes = [],
        public readonly ?string $raw = null,
    ) {}

    public static function post(int $id): self
    {
        return new self('post', $id);
    }

    public static function term(int $id): self
    {
        return new self('term', $id);
    }

    public static function user(int $id): self
    {
        return new self('user', $id);
    }

    public static function comment(int $id): self
    {
        return new self('comment', $id);
    }

    public static function menu(int $id): self
    {
        return new self('menu', $id);
    }

    public static function form(int $id): self
    {
        return new self('gform', $id);
    }

    public static function site(int $id): self
    {
        return new self('site', $id);
    }

    public static function archive(string $postType): self
    {
        return new self('archive', $postType);
    }

    public static function taxonomy(string $taxonomy): self
    {
        return new self('taxonomy', $taxonomy);
    }

    public static function role(string $role): self
    {
        return new self('role', $role);
    }

    public static function option(string $name): self
    {
        return new self('option', $name);
    }

    public static function language(string $slug): self
    {
        return new self('lang', $slug);
    }

    public static function nonce(): self
    {
        return new self('nonce');
    }

    /**
     * Build a tag of an arbitrary type — the escape hatch for tags outside the
     * named vocabulary (a custom integration's tags).
     */
    public static function of(string $type, int|string|null $id = null): self
    {
        return new self($type, $id);
    }

    /**
     * Normalise a string or Tag to a Tag.
     *
     * @param  string|Tag  $tag
     */
    public static function from($tag): self
    {
        return $tag instanceof self ? $tag : self::parse((string) $tag);
    }

    /** Coarse "any of this type" form, e.g. archive:post:any. */
    public function any(): self
    {
        return $this->qualify('any');
    }

    /** The "full"/pages qualifier, e.g. term:9:full. */
    public function full(): self
    {
        return $this->qualify('full');
    }

    /**
     * Set the qualifier — the general trailing variant (any, full, a language, …).
     */
    public function qualify(string $qualifier): self
    {
        return new self($this->type, $this->id, $qualifier, $this->scopes, $this->raw);
    }

    /**
     * Nest the tag under a scope dimension. Composable for any number of
     * dimensions; the first scope() is the outermost.
     */
    public function scope(string $dimension, int|string $value): self
    {
        return new self($this->type, $this->id, $this->qualifier, [...$this->scopes, [$dimension, $value]], $this->raw);
    }

    public function isRaw(): bool
    {
        return $this->raw !== null;
    }

    public function __toString(): string
    {
        $prefix = '';
        foreach ($this->scopes as [$dimension, $value]) {
            $prefix .= "{$dimension}:{$value}:";
        }

        $body = $this->isRaw()
            ? $this->raw
            : $this->type
                .($this->id !== null ? ":{$this->id}" : '')
                .($this->qualifier !== null ? ":{$this->qualifier}" : '');

        return $prefix.$body;
    }

    /**
     * Read a tag string into structure — the one place a tag string is parsed,
     * including peeling off leading scope dimensions.
     */
    public static function parse(string $tag): self
    {
        $scopes = [];
        $segments = explode(':', $tag);

        // Peel leading "dimension:value" scope pairs while a body remains after
        // them, so a bare "site:5" stays a tag but "site:5:post:3" is scoped.
        while (count($segments) > 2 && in_array($segments[0], self::SCOPE_DIMENSIONS, true)) {
            $dimension = array_shift($segments);
            $scopes[] = [$dimension, self::intIfCanonical(array_shift($segments))];
        }

        $type = $segments[0];
        $id = $segments[1] ?? null;
        $qualifier = $segments[2] ?? null;

        // A clean type:id(:qualifier) shape (≤ 3 segments) maps to fields; numeric
        // ids become ints. Anything else is kept verbatim as a raw tag.
        if ($type !== '' && count($segments) <= 3) {
            return new self(
                $type,
                $id !== null ? self::intIfCanonical($id) : null,
                $qualifier,
                $scopes,
            );
        }

        return new self('', null, null, $scopes, implode(':', $segments));
    }

    /**
     * Cast a segment to int only when it's a canonical integer, so a custom tag's
     * numeric-looking segment still round-trips verbatim (leading zeros like
     * "0123", or values past PHP_INT_MAX, are kept as strings).
     */
    private static function intIfCanonical(string $value): int|string
    {
        return ctype_digit($value) && (string) (int) $value === $value ? (int) $value : $value;
    }

    /**
     * Normalise a (possibly nested) array of strings/Tags to a flat Tag[].
     *
     * @param  array<string|Tag|array>  $tags
     * @return self[]
     */
    public static function fromMany(array $tags): array
    {
        return array_map([self::class, 'from'], Util::flatten($tags));
    }

    /**
     * @param  array<string|Tag>  $tags
     * @return string[]
     */
    public static function toStrings(array $tags): array
    {
        return array_map(fn ($tag) => (string) $tag, $tags);
    }
}
