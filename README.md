# sage-cachetags

A sage package for tracking what data rendered pages rely on using Cache Tags (inspired by [Drupal's Cache Tags](https://www.drupal.org/docs/drupal-apis/cache-api/cache-tags)).

## Example

Front page displays the page content as well as 3 recipe previews. The cache tags might be:

- `post:1` for the front page
- `post:232`, `post:233`, `post:234` for the 3 recipe previews
- `term:123`, `term:124` for a recipe category shown in the recipe previews
- `post:10` for a product name featured in one of the 3 recipes.

This set of tags will be gathered while rendering the page and then stored in the database and optionally added as a HTTP header.

When any of the  posts or terms are updated, page caches and reverse proxies know that the front page cache should be cleared.

## Installation

### Composer

```sh
composer require generoi/sage-cachetags
```

### Plugin

Download the zip, install like a regular plugin, then follow the [standalone installation](#standalone-without-acorn) instructions below.

### With Acorn (Sage theme)

Start by publishing the config/cachetags.php configuration file using Acorn:

```sh
wp acorn vendor:publish --provider="Genero\Sage\CacheTags\CacheTagsServiceProvider"
```

Edit it to your liking and if you're using the database store, scaffold the required database table:

```sh
wp acorn cachetags:database
```

### Standalone (without Acorn)

For WordPress sites without Acorn, use the `Bootstrap` class in your theme's `functions.php` or a mu-plugin. The `Bootstrap` class provides a fluent interface for configuration:

```php
use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Actions\HttpHeader;
use Genero\Sage\CacheTags\Invalidators\SuperCacheInvalidator;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;

// Bootstrap CacheTags using fluent interface
(new Bootstrap())
    ->store(WordpressDbStore::class)
    ->invalidators([SuperCacheInvalidator::class])
    ->actions([Core::class, HttpHeader::class])
    ->debug(defined('WP_DEBUG') && WP_DEBUG)
    ->httpHeader('Cache-Tag')
    ->bootstrap();
```

If you're using the database store, scaffold the required database table using WP-CLI:

```sh
wp cachetags database
```

Schema changes are migrated automatically on the next admin request after an
update (the table version is tracked in the `cachetags_db_version` option). On
headless or multisite setups, run `wp cachetags database` to apply migrations
across all sites.

## Invalidators

Currently it supports Kinsta Page Cache, WP Super Cache, SiteGround Optimizer, WP Rocket and Fastly. You can use multiple invalidators if you eg use Fastly in front of Kinsta and want to invalidate both.

**Coarse tags and full flushes.** A coarse tag like `archive:post` can resolve to
many stored URLs (every page that lists posts). The URL-based invalidators
(Kinsta, SiteGround, …) escalate to a **full cache flush** past a threshold rather
than firing thousands of individual purges — so on a busy editorial site a single
publish can flush the whole cache. This is intentional: those providers
effectively rate-limit purges, and over-purging is safe when we can't know the
exact set of URLs. The thresholds are tunable per provider (below). **Fastly is
unaffected** — it purges by `Surrogate-Key`, so the URL count is irrelevant, which
makes it the best fit for high-frequency editorial sites.

### Stored URL and query strings

Front-end pages are stored under the **actual requested URL** (including its query
string), so a URL-based purge matches the variant a page cache keyed on. A default
set of tracking/volatile params (`utm_*`, `gclid`/`fbclid`/`dclid`/…, `_wpnonce`,
`_`) is stripped and the rest sorted; keys longer than the `varchar(191)` column
fall back to the path.

On a **query-bypass** edge — Fastly (purges by `Surrogate-Key`, ignores the URL)
or Kinsta (query-string URLs bypass the cache entirely) — those query-string rows
are never cached and so never need purging; they just accumulate in the store
(one row per visited `?…` combination, including bot/scanner params). A
query-bypass site with heavy parameterised traffic can keep the store lean by
storing the path only:

```php
add_filter('cachetags/store-query-string', '__return_false');
```

**To match a URL-keyed edge that does cache query strings** (SiteGround, or Kinsta
configured to cache GET params) the strip list must equal that edge's — and that's
site-specific (our own Fastly VCLs strip anywhere from 5 to 16 params), so align
it per site:

```php
add_filter('cachetags/url-ignored-params', fn ($p) => [...$p, 'campaign_id', 'tduid']);
```

Comprehensive query-param normalization is better done at the edge (CDN/VCL) than
replicated here.

### SiteGround Optimizer

Integration exists if you add the `SiteGroundCacheInvalidator` invalidator in the `config/cachetags.php` file.

When more than 50 URLs need purging, the invalidator performs a full cache flush instead of purging each URL individually. This avoids overwhelming SiteGround's cache API with thousands of synchronous requests. The threshold is configurable:

```php
// Change the threshold (default: 50)
add_filter('cachetags/siteground-bulk-purge-threshold', fn () => 100);

// Always flush (never purge individual URLs)
add_filter('cachetags/siteground-bulk-purge-threshold', fn () => 0);
```

### Super Cache

Integration exists if you add the `SuperCacheInvalidator` invalidator in the `config/cachetags.php` file.

### Kinsta

Two invalidators, differing in how Kinsta resolves the purge:

- **`KinstaGroupCacheInvalidator`** (recommended) purges by `group|` — a prefix
  wildcard that clears a path together with everything beneath it: its pagination
  (`/shop/page/2/`) and its query-string variants (`/shop/?orderby=…`) in one
  request. It disables query-string storage (`cachetags/store-query-string`)
  since the bare path is enough, keeping the store lean. This is the right choice
  for a standard Kinsta setup, where query-string URLs bypass the cache anyway.
- **`KinstaCacheInvalidator`** purges by `single|` — the exact URL only. Use this
  if you've configured Kinsta to cache query-string URLs and need each variant
  purged by its full stored URL (see [Stored URL and query strings](#stored-url-and-query-strings)).

Add one of them to the `invalidator` list in `config/cachetags.php`. The site
root (`/`) is always purged exactly, so a group purge never flushes the whole
site.

### Cloudflare

Cloudflare Pro plan supports [HTTP header purging](https://blog.cloudflare.com/introducing-a-powerful-way-to-purge-cache-on-cloudflare-purge-by-cache-tag/) but an invalidor doesn't exist at the moment. If you're up for it, take a look at the Fastly one as an example.

### Fastly

There's both a `FastlySoftCacheInvalidator` and a `FastlyCacheInvalidator` (hard) cache invalidator for Fastly (Varnish) proxy cache. Using this set up you do not need a persistent `store` since Fastly works with HTTP headers. Example `config/cachetags.php`

```php
$isProduction = in_array(parse_url(WP_HOME, PHP_URL_HOST), [
    'www.example.com',
]);

return [
    'http-header' => 'Surrogate-Key',
    'store' => CacheTagStore::class,
    'invalidator' => array_filter([
        $isProduction ? FastlySoftCacheInvalidator::class : null,
    ]),
    'action' => [
        Core::class,
        HttpHeader::class,
    ],
];
````

## REST API integration

For headless/decoupled setups where pages are served from the WordPress REST
API, enable the `RestApi` action to tag REST read responses so a frontend or
CDN can purge them by cache tag:

```php
use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Actions\HttpHeader;
use Genero\Sage\CacheTags\Actions\RestApi;

return [
    'http-header' => 'Cache-Tag',
    'action' => [
        Core::class,
        HttpHeader::class,
        RestApi::class,
    ],
];
```

Keep `Core` enabled alongside it: block-derived tags from `content.rendered`
are still collected through Core's `render_block` hook during the REST request.

What gets tagged:

- **Single resources** (`/wp/v2/posts/123`, `/wp/v2/categories/5`, `/wp/v2/users/2`,
  `/wp/v2/comments/9`) — the object itself, plus a post's related terms, author,
  featured media and parent.
- **Collections** (`/wp/v2/posts`) — each item plus the relevant `archive:` /
  `taxonomy:` listing tag. The listing tag is added even for empty/filtered
  collections, so they refresh when their membership changes.
- **Search** (`/wp/v2/search`) — each matched post/term.
- **Headless post types** — public types plus any non-builtin post type/taxonomy
  exposed to REST (`show_in_rest`), so `public=false` content types are covered.

Only responses that may be publicly cached are tagged: requests are skipped when
they are authenticated, use `context=edit`, carry a `password`, or are not
`GET`/`HEAD`. The edge **must** strip the `Cache-Tag` header before it reaches
clients.

Each response is stored under its canonical URL with sort-normalized query
parameters, so variants that produce a different response — pagination/filters
(`?page=2`, `?categories=5`), `context`, and the server params that shape the
body (`_embed`, `_fields`, `_envelope`, `_locale`) — get distinct, CDN-matching
store keys and are purged separately. Parameters the route doesn't register (and
aren't response-shaping) are dropped so arbitrary client params can't fork the
key. Only the random per-request params `_wpnonce` and `_` are stripped
unconditionally — any cache entry keyed on them is never reused, so collapsing
them can't cause staleness.

### Custom routes

The `RestApi` action only knows about core `wp/v2` objects. A **custom public
route** that serves its own cacheable response (sets its own
`Cache-Control: public, s-maxage=…`, e.g. `my-plugin/v1/people`) is **cached at
the edge but never purged** unless it declares the cache tags its data depends on.

Do it the same way the front end does — add the tags while building the response,
from the `CacheTags` instance (`app(CacheTags::class)` with Acorn, or
`CacheTags::getInstance()` standalone). With `RestApi`/`HttpHeader` enabled they're
emitted and stored on `rest_post_dispatch`:

```php
public function handle(WP_REST_Request $request): WP_REST_Response
{
    $people = $this->search($request);

    CacheTags::getInstance()?->add([
        'archive:person',
        ...array_map(fn ($p) => "post:{$p->id}", $people),
    ]);

    return rest_ensure_response($people);
}
```

If the endpoint manages its **own** `Cache-Control` and you want full control, set
the header yourself (and `save()` the URL for url-based purge):

```php
$cacheTags->add($tags);
$cacheTags->save($request->get_route());
$response->header('Cache-Tag', implode(' ', $tags));
```

Purge them from a small custom `Action` that hooks the relevant
`transition_post_status` / meta / term events and calls `$cacheTags->clear([...])`,
mirroring `Core`. (For a third-party route you can't edit, the `cachetags/rest-tags`
filter below is the fallback.)

### Filters

```php
// Tag bespoke REST routes that don't map to a core object.
add_filter('cachetags/rest-tags', function (array $tags, WP_REST_Request $request) {
    return $request->get_route() === '/my-plugin/v1/feed'
        ? [...$tags, 'archive:post']
        : $tags;
}, 10, 2);

// Add or trim the related dependencies tagged for a post response.
// The matched WP_REST_Request is also passed as a third argument.
add_filter('cachetags/rest-related-tags', function (array $tags, WP_Post $post) {
    return $tags;
}, 10, 2);

// Change which query parameters are ignored when building the store URL.
add_filter('cachetags/rest-url-ignored-params', fn (array $params) => [...$params, 'preview']);
```

### Header size limits

Cache providers cap the tag header — Fastly's `Surrogate-Key` allows 1024 bytes
per key and 16384 bytes total, and **silently drops the offending key and every
key after it** once a limit is reached, which would leave content stale. To stay
safe (for both front-end pages and REST responses):

- Tags that aren't valid single header tokens — containing whitespace/control
  characters, or longer than the store column (191 bytes) — are dropped.
- When the combined header would exceed the byte budget, the per-object
  `post:`/`term:` tags are collapsed to their coarse `archive:{type}:any` /
  `taxonomy:{tax}:any` form, which is purged on any change to that post type or
  taxonomy. This over-purges rather than dropping tags.

```php
// Tag header byte budget before collapsing to coarse tags (default 16384,
// Fastly's Surrogate-Key total). Tune it for a provider with a different limit.
add_filter('cachetags/max-header-bytes', fn () => 8192);
```

(The single-tag length cap — 191, the `varchar(191)` store column — and the
header-token validation pattern are fixed, not filterable: they're tied to the
schema and to header safety.)

## Front-end tagging

With the `Core` action enabled, rendered pages are tagged automatically from the
template (single/page, taxonomy, author, post-type/date/search archives,
attachments) and from core blocks (queries, terms, authors, comments,
calendar/archives, site title/tagline/logo, etc.). Classic-theme `wp_nav_menu()`
output is tagged with its `menu:{id}` so menu edits purge the pages showing it.

Site-identity blocks (`core/site-title`, `core/site-tagline`, `core/site-logo`)
are tagged with an `option:{name}` tag and purged when that option changes.
Adjust which options are tracked with the `cachetags/options` filter:

```php
add_filter('cachetags/options', fn (array $options) => [...$options, 'my_option']);
```

Options not bound to a specific block are usually better handled with a full
cache flush than by tagging every page that might render them.

### Zero-config auto-tagging

For themes that render content through custom `WP_Query` loops (related posts,
curated lists) rather than the blocks `Core` understands, enable the opt-in
`AutoTag` action to tag every queried post and fetched term automatically:

```php
use Genero\Sage\CacheTags\Actions\AutoTag;
use Genero\Sage\CacheTags\Actions\Core;

return [
    'action' => [
        Core::class,
        AutoTag::class,
    ],
];
```

It hooks `posts_pre_query` (tagging each returned post, plus an `archive:{type}`
for collection queries) and `get_the_terms` (tagging each term). `posts_pre_query`
is used rather than `the_posts` because `get_posts()`/`get_children()`/
`get_pages()` force `suppress_filters=true` and so never fire `the_posts` — the
pre-query hook fires for every `WP_Query` regardless, so a plain
`foreach (get_posts(...) as $post)` loop is covered too. Raw `$wpdb` queries are
not (no query object to observe) — tag those explicitly. Page archives are
excluded by default — adjust with the `cachetags/autotag-excluded-archive-types`
filter. The header-size collapse keeps the broader tag set bounded.

## Cacheability

Some responses must never be stored in a shared cache. `Util::isCacheableRequest()`
returns `false` for previews and any request showing the admin bar (per-user
chrome baked into the HTML), and — by default — for logged-in users. When a
request is not cacheable the plugin skips tagging it and defines `DONOTCACHEPAGE`
so page caches (WP Super Cache, Batcache, theme cache-control providers) don't
store it; edge caches should consult `Util::isCacheableRequest()` from the
theme/VCL since their TTL header is sent earlier.

Integrations hook the single `cachetags/cacheable` filter. Responses are vetoed
at the default priority; opt-ins that re-enable logged-in users run earlier
(priority `<10`) so the vetoes always win:

- **`WooCommerce`** — non-cacheable on cart/checkout/account, `add-to-cart`/
  `wc-ajax`, an embedded login/register/lost-password form, and any page with a
  cart/checkout block or shortcode (these render per-user cart state).
- **`Gravityform`** — a form prepopulated from the query string is non-cacheable
  (its prefilled values are per-visitor, often PII).
- **`CacheCustomers`** (opt-in) — serve cached pages to logged-in customers/
  subscribers, who see identical catalog pages and whose admin bar WooCommerce
  hides. Enable only when the theme renders no per-user markup server-side and
  the edge stops bypassing their login cookie; cart/checkout/account still bail.
  Roll your own with an early-priority raise:

  ```php
  add_filter('cachetags/cacheable', fn ($c) => current_user_can('edit_posts') ? $c : true, 5);
  ```

## Nonces in cached pages

A page cached for hours can ship a **stale nonce**. WordPress nonces are valid
for 12–24h; once one ages out, the action it guards (a form submit, an AJAX
"load more", an add-to-cart) starts failing for everyone served the cached page.

Two ways to handle a page that bakes a nonce into its HTML:

1. **Tag it `nonce` and enable the nonce cron.** The page is then purged every
   12 hours, before any embedded nonce can expire. Enable `'nonce-cron' => true`
   in the config (or `->nonceCron()` on `Bootstrap`), and add the tag wherever
   the nonce is rendered:

   ```php
   // e.g. a product page that prints a WooCommerce Store API nonce for add-to-cart
   add_action('wp_footer', function () {
       if (function_exists('is_product') && is_product()) {
           \Genero\Sage\CacheTags\CacheTags::getInstance()?->add(['nonce']);
       }
   });
   ```

   The `Gravityform` action already does this for file-upload fields.

2. **Mark it non-cacheable** when the page also shows genuinely real-time data
   (e.g. live availability), where a 12h refresh isn't enough:

   ```php
   add_filter('cachetags/cacheable', fn ($c) => is_page('booking') ? false : $c);
   ```

Note that modern WooCommerce (10.7+) refetches the Store API nonce client-side
before a write, so the sitewide Store API nonce is no longer a staleness risk on
its own — only nonces that are actually *used as rendered* need this treatment.

## Traits for use with roots/sage

### Composers

```php
namespace App\View\Composers;

use Genero\Sage\CacheTags\Concerns\ComposerCacheTags;
use Genero\Sage\CacheTags\Tags\CoreTags;
use Roots\Acorn\View\Composer;
use Illuminate\View\View;

class ContentSingle extends Composer
{
    use ComposerCacheTags;

    protected static $views = [
        'partials.content-single',
    ];

    /**
     * @return array
     */
    public function with()
    {
        $post = get_post();

        return [
            'post' => $post,
            'date' => $this->date($post),
            'authors' => $this->authors($post),
            'excerpt' => $this->excerpt($post),
            'related' => $this->related($post),
            'categories' => $this->categories($post),
        ];
    }

    public function cacheTags(View $view): array
    {
        return [
            ...CoreTags::posts($view->post),
            ...CoreTags::terms($view->categories),
            ...CoreTags::query($this->related())
        ];
    }
}

```

### ACF Blocks

```php
namespace App\Blocks;

use Genero\Sage\CacheTags\Tags\CoreTags;
use Genero\Sage\CacheTags\Concerns\BlockCacheTags;

class ArticleList extends Block
{
    use BlockCacheTags;

    public $name = 'Article List';
    public $slug = 'article-list';

    public function cacheTags(): array
    {
        $query = $this->buildQuery();

        return [
            ...CoreTags::archive('post'),
            ...CoreTags::query($query),
        ];
    }
}
```

## Integrations

### WooCommerce & Polylang (auto-enabled)

When WooCommerce or Polylang is active, its action is enabled automatically — you
don't need to list it in `action`:

- **WooCommerce** keeps cart/checkout/account out of the shared cache and purges a
  product (plus its archives) on price/stock/status changes.
- **Polylang** makes archive tags language-specific (`archive:post:fi`) so a change
  in one language only purges that language's listings, and clears the right
  language archives on publish/unpublish/delete.

To manage the action list entirely yourself, turn detection off:

```php
// config/cachetags.php
'auto-detect-actions' => false,
```

```php
// or on the standalone bootstrap
(new Bootstrap)->autoDetectActions(false)->/* … */->bootstrap();
```

### The `Site` action

Enable the `Site` action to tag every WordPress-served page with a `site:{id}` key
and prefix all other tags with it (`site:1:post:123`). Two uses, both common:

- **Flush all dynamic pages in one purge — even on a single site.** Every
  WP-rendered page carries `site:1`, but static assets (images/CSS/JS) don't, so
  purging that one tag clears the whole site's pages at the edge while leaving
  assets cached:

  ```sh
  wp cachetags clear site:1
  ```

- **Multisite scoping.** When one edge (e.g. a single Fastly service) fronts the
  whole network, the `site:{id}:` prefix keeps a purge on one site from clearing
  same-id content (`post:123`) on another.

### Multisite tables

Each site has its own `cache_tags` table, provisioned on activation and when a new
subsite is created. Run `wp cachetags database` to (re)scaffold every site —
useful after activating on a large network where the activation request can't
finish provisioning all of them.

## CLI

**With Acorn:**

```sh
# Flush the entire cache
wp acorn cachetags:flush

# Clear specific tags
wp acorn cachetags:clear post:1 term:5

# Scaffold database table (all sites on multisite)
wp acorn cachetags:database

# Migrate the table to the latest schema (drop + recreate + flush the cache)
wp acorn cachetags:database --rebuild

# Inspect the store: row/tag/url counts and the widest-fan-out tags
wp acorn cachetags:status
# …or the tags a given URL is stored under
wp acorn cachetags:status --url=https://example.com/article/
```

**Standalone:**

```sh
# Flush the entire cache
wp cachetags flush

# Clear specific tags
wp cachetags clear post:1 term:5

# Scaffold database table (all sites on multisite)
wp cachetags database

# Migrate the table to the latest schema (drop + recreate + flush the cache)
wp cachetags database --rebuild

# Inspect the store
wp cachetags status
wp cachetags status --url=https://example.com/article/
```

`status` answers "what's bloating the store / why was this purged so widely" — a
tag with a high URL count purges that many pages on a single change. It requires
a store that supports inspection (the default `WordpressDbStore` does).

The store is a rebuildable cache, so `--rebuild` migrates an existing (even
million-row) table to the latest schema by dropping and recreating it — avoiding
a slow, locking `ALTER` — and flushes the edge so nothing is left stale while the
store refills. Run it in a low-traffic window; the cold cache warms as pages
re-render.

## API

### Accessing CacheTags instance

**With Acorn:**
```php
use Genero\Sage\CacheTags\CacheTags;

// Get instance from container
$cacheTags = app(CacheTags::class);
```

**Standalone:**
```php
use Genero\Sage\CacheTags\CacheTags;

// Get the singleton instance
$cacheTags = CacheTags::getInstance();
```

### Building tags with `Tag`

Tags are `Tag` value objects — fluent to build, serialized to their string form
(`post:5`, `archive:post:any`, `site:5:term:9`) only at the edge (header, store,
purge). `add()` and `clear()` accept Tags, plain strings, and nested arrays
interchangeably, so you rarely touch `Tag` directly — but it's there when you want
type-safety or context.

```php
use Genero\Sage\CacheTags\Tag;

Tag::post(5);                    // post:5
Tag::archive('product');         // archive:product
Tag::archive('product')->any();  // archive:product:any  (any product changing)
Tag::term(9)->full();            // term:9:full
Tag::option('blogname');         // option:blogname
Tag::of('my-type', $id);         // an arbitrary custom type
```

Context is two general, composable operations — `scope()` to namespace a tag and
`qualify()` for a variant — so new dimensions (a multisite network, a tenant) need
no new API:

```php
Tag::post(5)->scope('site', 5);                       // site:5:post:5
Tag::post(5)->scope('network', 2)->scope('site', 5);  // network:2:site:5:post:5
Tag::archive('post')->qualify($lang);                 // archive:post:fi
```

The builder classes (`CoreTags`, `WooCommerceTags`, `SiteTags`, `PolylangTags`,
`GravityformTags`) return `Tag[]`; pass them — and any plain strings — straight to
`add()`/`clear()`:

```php
$cacheTags->add([
    Tag::archive('product'),
    ...CoreTags::posts($ids),   // CoreTags returns Tag[]
    'my:custom:tag',            // plain strings still work
]);
```

### Create a custom tag

The nicest way is to look at the code of this repo and create a custom `Action`, but the logic is really nothing more than:

**With Acorn:**
```php
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Tag;

// Tag content (a bare string, or Tag::of('custom-tag') / Tag::of('thing', $id))
app(CacheTags::class)->add(['custom-tag']);

// Clear it whenever you want
\add_action('custom/update', fn() => app(CacheTags::class)->clear(['custom-tag']));
```

**Standalone:**
```php
use Genero\Sage\CacheTags\CacheTags;

// Tag content
CacheTags::getInstance()?->add(['custom-tag']);

// Clear it whenever you want
\add_action('custom/update', fn() => CacheTags::getInstance()?->clear(['custom-tag']));
```
