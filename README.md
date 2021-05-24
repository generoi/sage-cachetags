# sage-cachetags

## Usage

### Composers

```php
namespace App\View\Composers;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Concerns\ComposerCacheTags;
use Roots\Acorn\View\Composer;

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
            'date' => $this->date($post),
            'authors' => $this->authors($post),
            'excerpt' => $this->excerpt($post),
            'related' => $this->related($post),
        ];
    }

    public function cacheTags(): array
    {
        return [
            ...CacheTags::getPostCacheTags(),
            ...CacheTags::getQueryCacheTags($this->related())
        ];
    }
}

```

### ACF Blocks

```php
namespace App\Blocks;

use Genero\Sage\CacheTags\CacheTags;
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
            ...CacheTags::getArchiveCacheTags('post'),
        ];
    }
}
```
