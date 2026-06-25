# Upgrading

## 3.0

3.0 reworks cache tags from plain strings into a structured `Tag` value object
internally. **Most projects need no code changes** — bump the composer constraint
and you're done. The one breaking change affects only code that treats the tag
*builder* output as strings.

Audited against solarplexius, herrfors, beamex and kaskipuu: all four needed only
the composer bump (plus, for herrfors, a one-line test tweak — see below).

### Required: composer constraint

```diff
-    "generoi/sage-cachetags": "^2.5"
+    "generoi/sage-cachetags": "^3.0"
```

### Breaking: tag builders return `Tag[]`, not `string[]`

`CoreTags`, `SiteTags`, `PolylangTags`, `WooCommerceTags` and `GravityformTags`
builder methods (`posts()`, `archive()`, `terms()`, `anyTerm()`, `navigation()`,
…) now return an array of `Tag` objects instead of strings.

**No change needed** when you pass their output to `CacheTags::add()` / `clear()`
— the common case, including the `BlockCacheTags` / `ComposerCacheTags` traits.
Tags stringify, and `add()`/`clear()` accept strings *and* Tags, mixed:

```php
$cacheTags->add([...CoreTags::archive('product'), 'my:custom:tag']); // fine
```

**Change needed** only if you consume a builder's return value *as strings* —
`implode()`, a strict `in_array('post:5', $tags, true)`, `===`, persisting them,
or passing them to something typed `string[]`. Wrap with `Tag::toStrings()`:

```diff
-$strings = CoreTags::posts($ids);
+$strings = Tag::toStrings(CoreTags::posts($ids));
```

Your own custom string tags, the `cachetags/filter-tags` filter, and the
`Store`/`Invalidator` payloads are all still plain strings — unchanged.

### Internal state is now `Tag[]`

If you reflect into `CacheTags`' protected `cacheTags` / `purgeTags` properties
(e.g. in a test), they now hold `Tag` objects. Cast before string assertions:

```diff
-$queued = $reflectedPurgeTags;
+$queued = Tag::toStrings($reflectedPurgeTags);
```

### New (optional): the fluent `Tag` API

Build tags expressively — context is two general, composable operations
(`scope()` for namespacing, `qualify()` for a variant), so new dimensions need no
new API:

```php
Tag::post(5)
Tag::archive('post')->any()
Tag::term(9)->full()
Tag::post(5)->scope('site', 5)                       // multisite
Tag::post(5)->scope('network', 2)->scope('site', 5)  // composes
Tag::archive('post')->qualify($lang)                 // language variant
Tag::of('my-type', $id)                              // arbitrary type
```

### New default: a `page` base tag on every page

Every cacheable page and REST response is now tagged with `page`, so
`wp cachetags clear page` flushes all WordPress-served pages (assets stay cached).
It's additive — one extra tag per page. Rename or disable it with
`'base-tag' => null` (config) or `->baseTag(null)` (bootstrap). On single sites
this replaces the trick of enabling the `Site` action just to get a flush-all key.

### Unchanged

Compatible without changes: `CacheTags::add/clear/save/flush/getInstance/hasAction`
and the public `invalidators` property; every `cachetags/*` filter (incl.
`cachetags/filter-tags`, still `string[] → string[]`); the `Store`, `Invalidator`
and `Action` contracts; the bundled actions/invalidators/stores; the `Bootstrap`
fluent API and `CacheTagsServiceProvider`; `Util::normalizeTags()`; the config
keys; and the WP-CLI commands.
