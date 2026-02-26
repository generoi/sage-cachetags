<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Util;

class DebugComment implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        if ($this->cacheTags->debug) {
            \add_action('wp_footer', [$this, 'printCacheTagsDebug']);
        }
    }

    public function printCacheTagsDebug(): void
    {
        $cacheTags = array_map(
            function ($tag) {
                $subtag = $tag;
                if (str_starts_with($tag, 'site:')) {
                    [$prefix, $site, $subtag] = explode(':', $tag.':', 3);
                }
                [$entity, $id] = explode(':', $subtag.':');

                switch ($entity) {
                    case 'menu':
                    case 'term':
                        return sprintf('[%s] %s (%s)', $tag, get_term($id)->name, get_term($id)->taxonomy);
                    case 'comment':
                        return sprintf('[%s] %s', $tag, get_comment($id)->comment_author);
                    case 'post':
                        return sprintf('[%s] %s (%s)', $tag, get_post($id)?->post_title ?: $id, get_post_type($id));
                    default:
                        return sprintf('[%s]', $tag);
                }
            },
            $this->cacheTags->get()
        );

        echo sprintf('
            <!-- sage-cachetags
            Url: %s
            Tags: %s
            -->
        ', $this->currentUrl(), implode(PHP_EOL.str_repeat(' ', 18), $cacheTags));
    }

    protected function currentUrl(): string
    {
        return Util::currentUrl();
    }
}
