<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Roots\Acorn\Application;

class HttpHeader implements Action
{
    protected Application $app;
    protected CacheTags $cacheTags;

    public function __construct(Application $app, CacheTags $cacheTags)
    {
        $this->app = $app;
        $this->cacheTags = $cacheTags;
    }

    public function bind(): void
    {
        \add_action('wp_footer', [$this, 'addHttpHeader']);
    }

    public function addHttpHeader(): void
    {
        if ($header = $this->app->config->get('cachetags.http-header')) {
            header(sprintf(
                '%s: %s',
                $header,
                collect($this->cacheTags->get())->join(' ')
            ));
        }
    }
}
