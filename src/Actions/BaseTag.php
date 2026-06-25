<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;

/**
 * Tag every cacheable page and REST response with a single base tag, so one
 * purge clears all WordPress-served pages at once while static assets (which
 * never carry it) stay cached. Wired by Bootstrap with the configured tag name;
 * disable by setting the base tag to null.
 */
class BaseTag implements Action
{
    public function __construct(protected CacheTags $cacheTags, protected string $tag = 'page') {}

    public function bind(): void
    {
        \add_action('template_redirect', [$this, 'addBaseTag']);
        \add_action('rest_api_init', [$this, 'addBaseTag']);
    }

    public function addBaseTag(): void
    {
        $this->cacheTags->add($this->tag);
    }
}
