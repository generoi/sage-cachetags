<?php

namespace Genero\Sage\CacheTags;

/**
 * A cache tag as structured data.
 *
 * Tags are strings at every boundary (the Cache-Tag header, the store, the
 * invalidators, the cachetags/filter-tags filter, custom code calling add()),
 * but reasoning about them — applying a multisite scope or a language, or
 * collapsing a high-cardinality tag to its coarse form — is cleaner on fields
 * than on strings. This object is that structured form; parse() and __toString()
 * are the single place a tag string is read or written, so the rest of the
 * codebase never parses tags.
 *
 * Unrecognised strings (a site's own custom tags) round-trip exactly via the
 * `raw` field, so the structured model is fully backwards compatible.
 */
final class Tag implements \Stringable
{
    /** Tag types whose identifier is a numeric id. */
    const NUMERIC_TYPES = ['post', 'term', 'user', 'comment', 'menu', 'gform', 'site'];

    /** Tag types whose identifier is a name/slug. */
    const NAMED_TYPES = ['archive', 'taxonomy', 'role', 'option', 'lang'];

    /** Tag types with no identifier. */
    const BARE_TYPES = ['nonce'];

    private function __construct(
        public readonly string $type,
        public readonly int|string|null $identifier = null,
        public readonly ?string $qualifier = null,
        public readonly ?int $scope = null,
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

    public static function gform(int $id): self
    {
        return new self('gform', $id);
    }

    public static function site(int $id): self
    {
        return new self('site', $id);
    }

    public static function archive(string $name): self
    {
        return new self('archive', $name);
    }

    public static function taxonomy(string $name): self
    {
        return new self('taxonomy', $name);
    }

    public static function role(string $name): self
    {
        return new self('role', $name);
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
     * A tag whose string form isn't part of the known grammar (a site's custom
     * tag). Carried verbatim so it round-trips exactly.
     */
    public static function raw(string $literal): self
    {
        return new self('', null, null, null, $literal);
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

    public function any(): self
    {
        return $this->withQualifier('any');
    }

    public function full(): self
    {
        return $this->withQualifier('full');
    }

    public function inLanguage(string $lang): self
    {
        return $this->withQualifier($lang);
    }

    public function withQualifier(?string $qualifier): self
    {
        return new self($this->type, $this->identifier, $qualifier, $this->scope, $this->raw);
    }

    public function withScope(?int $scope): self
    {
        return new self($this->type, $this->identifier, $this->qualifier, $scope, $this->raw);
    }

    public function isRaw(): bool
    {
        return $this->raw !== null;
    }

    public function __toString(): string
    {
        $body = $this->isRaw()
            ? $this->raw
            : $this->type
                .($this->identifier !== null ? ":{$this->identifier}" : '')
                .($this->qualifier !== null ? ":{$this->qualifier}" : '');

        return $this->scope !== null ? "site:{$this->scope}:{$body}" : $body;
    }

    /**
     * Read a tag string into structure. The one place a tag string is parsed —
     * including peeling off an optional multisite "site:N:" scope prefix.
     */
    public static function parse(string $tag): self
    {
        $scope = null;
        if (preg_match('/^site:(\d+):(.+)$/', $tag, $matches)) {
            $scope = (int) $matches[1];
            $tag = $matches[2];
        }

        $parts = explode(':', $tag);
        $type = $parts[0];

        // A bare "site:N" tag (the scope tag itself, not a prefix).
        if ($type === 'site' && $scope === null && count($parts) === 2 && ctype_digit($parts[1])) {
            return new self('site', (int) $parts[1]);
        }

        if (in_array($type, self::NUMERIC_TYPES, true) && isset($parts[1]) && ctype_digit($parts[1])) {
            return new self($type, (int) $parts[1], $parts[2] ?? null, $scope);
        }

        if (in_array($type, self::NAMED_TYPES, true) && isset($parts[1]) && $parts[1] !== '') {
            return new self($type, $parts[1], $parts[2] ?? null, $scope);
        }

        if (in_array($type, self::BARE_TYPES, true) && count($parts) === 1) {
            return new self($type, null, null, $scope);
        }

        // Unknown shape — keep the literal so custom tags round-trip exactly.
        return new self('', null, null, $scope, $tag);
    }

    /**
     * @param  array<string|Tag>  $tags
     * @return self[]
     */
    public static function fromMany(array $tags): array
    {
        return array_map([self::class, 'from'], $tags);
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
