<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Roots\Acorn\Application;

class DebugComment implements Action
{
    protected CacheTags $cacheTags;

    public function __construct(Application $app, CacheTags $cacheTags)
    {
        $this->app = $app;
        $this->cacheTags = $cacheTags;
    }

    public function bind(): void
    {
        if ($this->app->config->get('cachetags.debug')) {
            \add_action('wp_footer', [$this, 'printCacheTagsDebug']);
        }
    }

    public function printCacheTagsDebug(): void
    {
        $cacheTags = collect($this->cacheTags->get())
            ->map(function ($tag) {
                $label = null;
                [$entity, $id,] = explode(':', $tag);

                switch ($entity) {
                    case 'menu':
                    case 'term':
                        return sprintf('[%s] %s', $tag, get_term($id)->name);
                    case 'comment':
                    case 'post':
                        return sprintf('[%s] %s', $tag, get_post($id)->post_title);
                    default:
                        return sprintf('[%s]', $tag);
                }
            });

        echo sprintf("
            <!-- sage-cachetags
            Url: %s
            Tags: %s
            -->
        ", $this->currentUrl(), $cacheTags->join(PHP_EOL . str_repeat(' ', 18)));
    }

    /**
     * Return the current page url.
     */
    protected function currentUrl(): string
    {
        global $wp;
        return \home_url($wp->request);
    }
}
