# sage-cachetags

A sage package for tracking what data rendered pages rely on using Cache Tags (inspired by [Drupal's Cache Tags](https://www.drupal.org/docs/drupal-apis/cache-api/cache-tags)).

## Example

Front page displays the the page content as well 3 recipe previews. The cache tags might be:

- `post:1` for the front page
- `post:232`, `post:233`, `post:234` for the 3 recipe previews
- `term:123`, `term:124` for a recipe category shown in the recipe previews
- `post:10` for a product name featured in one of the 3 recipes.

This set of tags will be gathered while rendering the page and then stored in the database and optionally added as a HTTP header.

When any of the  posts or terms are updated, page caches and reverse proxies know that the front page cache should be cleared.

## Installation

```sh
composer require generoi/sage-cachetags
```

Start by publishing the config/cachetags.php configuration file using Acorn:

```sh
wp acorn vendor:publish --provider="Genero\Sage\CacheTags\CacheTagsServiceProvider"
```

Edit it to your liking and if you're using the database store, scaffold the required database table:

```sh
wp acorn cachetags:database
```

## Usage

Currently it only supports WP Super Cache and SiteGround Optimizer but plan is to integrate with Cloudflare and other page caches.

### SiteGround Optimizer

Integration eists if you add the invalidator in the `config/cachetags.php` file.

### Super Cache

Integration exists if you add the invalidator in the `config/cachetags.php` file.

### Cloudflare

If you have a cloudflare pro plan you can already use this package with the HTTP headers. The goal is to add support for the basic plans as well.

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
