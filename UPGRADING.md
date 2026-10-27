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

Your own custom string tags and the `Store`/`Invalidator` payloads are all still
plain strings — unchanged.

### `cachetags/filter-tags` now passes `Tag[]`

The `cachetags/filter-tags` filter receives and returns an array of `Tag` objects
instead of strings, so tags stay structured through the whole pipeline. A filter
that built strings can use the `Tag` API instead, and returning plain strings
still works (they're parsed back):

```diff
 add_filter('cachetags/filter-tags', function (array $tags) {
-    $tags[] = "site:{$id}:" . $tag;          // string munging
+    $tags[] = Tag::of($type, $id)->scope('site', $id);
     return $tags;
 });
```

Output is still validated before it reaches the header, so a filter can't smuggle
a header-unsafe tag through. The bundled `Site` and `Polylang` actions already use
this. (No release shipped a consumer of this filter; this only matters if you hook
it yourself.)

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

### New defaults: auto-enabled integrations & nonce cron

- **Gravity Forms** now auto-enables (joining WooCommerce and Polylang) when its
  plugin is active — it no longer needs to be listed in `action`. If you publish
  the config, `Gravityform::class` is dropped from the default `action` list (it's
  detected instead). Turn detection off with `'auto-detect-actions' => false`.
- **Nonce cron** is now a default `Nonce` action (in the published config's
  `action` list) that schedules the 12-hour purge of `nonce`-tagged pages. The
  `'nonce-cron'` config key and `Bootstrap::nonceCron()` are **removed** — it's on
  by default (a light twice-daily cron), so any page tagged `nonce`, including a
  theme's own, is covered without wiring a cron. Opt out by removing `Nonce::class`
  from `action`.

### New default: store garbage collection

The `WordpressDbStore` now garbage-collects itself: a daily cron prunes rows whose
URL hasn't rendered within `'prune-older-than'` (default `30d`), so query-string /
bot / campaign-link variants don't accumulate forever. To make this safe, `save()`
switched from `INSERT IGNORE` to an upsert that refreshes a row's `created_at`
("last seen") at most once a day — so a frequently-rendered URL isn't written on
every cache miss, and live pages are never pruned. **Set `'prune-older-than'` above
your edge cache's max TTL** (≈30d on Kinsta/Fastly), or disable GC with
`'prune-older-than' => null`. `wp cachetags prune --older-than=…` runs it manually.

### New (optional): Fastly query-param allowlist

Opt-in (default off). Name a Fastly Edge Dictionary in `'fastly-allowlist-dictionary'`
and `wp cachetags fastly-allowlist sync` pushes the cache-significant query params
(WooCommerce/FacetWP/search) to it, for a static VCL snippet to filter the cache
key. No migration — additive and off by default. See the README.

### Unchanged

Compatible without changes: `CacheTags::add/clear/save/flush/getInstance/hasAction`
and the public `invalidators` property; the config filters (`cachetags/cacheable`,
`cachetags/url-ignored-params`, `cachetags/options`, …); the `Store`, `Invalidator`
and `Action` contracts; the bundled actions/invalidators/stores; the `Bootstrap`
fluent API and `CacheTagsServiceProvider`; `Util::normalizeTags()`; the config
keys; and the WP-CLI commands. (`cachetags/filter-tags` is the one filter whose
signature changed — see above.)
