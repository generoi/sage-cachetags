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

## Invalidators

Currently it supports Kinsta Page Cache, WP Super Cache, SiteGround Optimizer and Fastly. You can use multiple invalidators if you eg use Fastly in front of Kinsta and want to invalidate both.

### SiteGround Optimizer

Integration exists if you add the `SiteGroundCacheInvalidator` invalidator in the `config/cachetags.php` file.

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
