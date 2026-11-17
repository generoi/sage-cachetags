# Upgrading

## 3.4

No changes needed. New, off by default: `'kinsta-remove-term-menu-clears' =>
true` stops kinsta-mu-plugins clearing the whole site on every term edit, term
deletion and menu save, which MyKinsta's autopurge setting can't stop and
Polylang triggers on each translation link. The tag purges cover those saves.
See "Kinsta's full clears on term and menu saves" in the README.

## 3.3

No code changes are needed, except in theme tests that assert
`CoreTags::navigation()`'s output (see below). These behaviours change:

- **`CoreTags::menu()` no longer throws on a menu it can't resolve.** It returns
  no tags. Themes call it on every render (Navi menus), so a location still
  pointing at a deleted menu turned into a 500 on every page, where
  `wp_nav_menu()` or Navi would just render nothing. Code that relied on the
  exception to catch a typo'd slug should check `wp_get_nav_menu_object()`
  itself.
- **`CoreTags::navigation()` also returns `menu-location:{location}`**, before
  the menu's `menu:{id}` and also for an empty location, so assigning a menu to
  a location purges the pages rendering it. A theme test asserting
  `navigation()` returns exactly `['menu:5']` now gets
  `['menu-location:primary', 'menu:5']`.
- **Term archives are no longer purged on every save of a post in them.** The
  classic editor and Quick Edit re-set a post's terms on every save, which
  purged all of its term archives each time; now only terms added to or removed
  from the post purge their archives. The post itself is purged as before.
- **Searches, filters and sorts aren't stored under the bare URL.** With
  `cachetags/store-query-string` off (which `KinstaGroupCacheInvalidator` sets),
  a render that differs from the bare URL's page — a search, a WordPress public
  query var, or a sort/filter param in `cachetags/render-params` (`orderby`,
  `filter_*`, `min_price`, `fwp_*`, …) — saves no store rows. Before, `/?s=x`
  was stored as `/`, so the front page collected every search result's tags
  and was purged by all of them; Kinsta doesn't cache those URLs. Every other
  param still maps to the bare URL as in 3.2, so an unlisted tracking param
  keeps the bare URL purgeable. If a theme param changes the render (`?view=`),
  add it to `cachetags/render-params`. With a Kinsta invalidator, `_ga`,
  `mc_cid`, `mc_eid` and `ref` are also stripped from the stored URL (Kinsta
  serves them from the bare URL's entry). The tag header is unchanged. The extra
  tags already stored on `/` go with its next purge, which forgets every tag of
  a purged URL.
- **`cachetags:status` takes the URL as an argument:**
  `wp acorn cachetags:status https://example.com/article/` or
  `wp cachetags status https://example.com/article/`. WP-CLI takes `--url` as
  its own global flag, so `--url=<page>` printed the store-wide totals instead
  (and set WP-CLI's site URL). The Acorn command still accepts `--url` where it
  reaches it. On multisite, pick the site with WP-CLI's own flag:
  `wp --url=https://sub.example.com cachetags status https://sub.example.com/article/`.
- **Kinsta: language homes are no longer group-purged.**
  `KinstaGroupCacheInvalidator` purges the site home and each Polylang language
  home (`/sv/`) exactly, where a `group|/sv/` purge used to empty that whole
  language. Those wide purges also hid missing tags: a page under `/sv/` that
  carries no tag for something it shows was still cleared whenever the language
  home was. If such pages go stale after upgrading, tag them for what they
  show.

New purges to expect:

- Saving, trashing or resetting a template or template part purges the pages
  rendering it (`template:` / `template-part:{theme}//{slug}`, per language with
  Polylang) — also a draft or auto-draft, since `block_template_part()` renders
  those publicly, not only a published one.
- Saving global styles or the Customizer's **Additional CSS** for the active
  theme, or adding or removing a hierarchy template that no theme file backs
  and no plugin registers (`register_block_template()`), clears the base tag
  (`page`), so **every page**; with `'base-tag' => null` those purge nothing.
  WooCommerce's category, tag and brand templates aren't registered (those
  pages resolve to `archive-product`), so customising one clears it too.
- A theme action that purged on these saves (or flushed the whole cache) can be
  removed. Pages rendered before the upgrade don't carry the new tags yet, so
  flush once after deploying.
- Deleting a menu purges the pages showing it.
- With Polylang, post and term pages carry a `translations:{group}` tag:
  linking or unlinking a translation, or changing a translation's URL (slug,
  parent, status, the date with a date-based permalink structure), purges the
  pages of every translation in the group, since their language switchers link
  to it. A content or title edit purges only its own language.
- Changing a linked post's title, slug, parent, status or (with a date
  permalink) date, its category with a `%category%` permalink (`%product_cat%`
  for products), or a linked term's name, slug or parent, purges the menus
  linking to it. Assigning a menu location (even one that was empty) purges the
  pages rendering it, per language with Polylang.

Still needing a manual flush (see "Known limits" in the README): child pages
under a renamed parent, a renamed term in a `%category%` permalink, Polylang's
direct-SQL parent sync, a Polylang default-language change, Polylang's bulk
"set language" (`set_translation_in_mass`), a Polylang language slug change and
WXR imports.

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

### Unchanged

Compatible without changes: `CacheTags::add/clear/save/flush/getInstance/hasAction`
and the public `invalidators` property; the config filters (`cachetags/cacheable`,
`cachetags/url-ignored-params`, `cachetags/options`, …); the `Store`, `Invalidator`
and `Action` contracts; the bundled actions/invalidators/stores; the `Bootstrap`
fluent API and `CacheTagsServiceProvider`; `Util::normalizeTags()`; the config
keys; and the WP-CLI commands. (`cachetags/filter-tags` is the one filter whose
signature changed — see above.)
