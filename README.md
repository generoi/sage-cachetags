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

Currently it supports Kinsta Page Cache, WP Super Cache, SiteGround Optimizer and Fastly. You can use multiple invalidators if you eg use Fastly in front of Kinsta and want to invalidate both.

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

Integration exists if you add the `KinstaCacheInvalidator` in the `config/cachetags.php` file.

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
// Tag header byte budget before collapsing to coarse tags (default 16384).
add_filter('cachetags/max-header-bytes', fn () => 8192);

// Maximum length of a single tag (default 191, the store column width).
add_filter('cachetags/max-tag-length', fn () => 191);

// Pattern a tag must match to be kept.
add_filter('cachetags/tag-pattern', fn () => '/^[^\s\x00-\x1F]+$/');
```

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

## Cacheability

Some responses must never be stored in a shared cache. `Util::isCacheableRequest()`
returns `false` for previews and any request showing the admin bar (per-user
chrome baked into the HTML), and — by default — for logged-in users. When a
request is not cacheable the plugin skips tagging it and defines `DONOTCACHEPAGE`
so page caches (WP Super Cache, Batcache, theme cache-control providers) don't
store it; edge caches should consult `Util::isCacheableRequest()` from the
theme/VCL since their TTL header is sent earlier.

Integrations veto via `cachetags/cacheable` (runs last) and opt in via
`cachetags/cache-logged-in`:

- **`WooCommerce`** — non-cacheable on cart/checkout/account, `add-to-cart`/
  `wc-ajax`, an embedded login/register/lost-password form, and any page with a
  cart/checkout block or shortcode (these render per-user cart state).
- **`Gravityform`** — a form prepopulated from the query string is non-cacheable
  (its prefilled values are per-visitor, often PII).
- **`CacheCustomers`** (opt-in) — serve cached pages to logged-in customers/
  subscribers, who see identical catalog pages and whose admin bar WooCommerce
  hides. Enable only when the theme renders no per-user markup server-side and
  the edge stops bypassing their login cookie; cart/checkout/account still bail.

  ```php
  add_filter('cachetags/cacheable', fn ($c) => $c); // (vetoes still apply)
  ```

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

## CLI

**With Acorn:**

```sh
# Flush the entire cache
wp acorn cachetags:flush

# Scaffold database table
wp acorn cachetags:database
```

**Standalone:**

```sh
# Flush the entire cache
wp cachetags flush

# Scaffold database table
wp cachetags database
```

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

### Create a custom tag

The nicest way is to look at the code of this repo and create a custom `Action` and maybe a `CustomTag` class that you use, but the logic is really nothing more than:

**With Acorn:**
```php
use Genero\Sage\CacheTags\CacheTags;

// Tag content
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
