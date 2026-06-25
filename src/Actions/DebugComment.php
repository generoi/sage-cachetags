<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tag;
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
                // Parse to ignore any scope prefix (site:/network:) and read the
                // underlying entity, so a scoped tag annotates like a bare one.
                $parsed = Tag::parse($tag);
                $id = (int) $parsed->id;

                switch ($parsed->type) {
                    case 'menu':
                    case 'term':
                        $term = get_term($id);

                        return $term instanceof \WP_Term
                            ? sprintf('[%s] %s (%s)', $tag, esc_html($term->name), esc_html($term->taxonomy))
                            : sprintf('[%s]', $tag);
                    case 'comment':
                        $comment = get_comment($id);

                        return $comment instanceof \WP_Comment
                            ? sprintf('[%s] %s', $tag, esc_html($comment->comment_author))
                            : sprintf('[%s]', $tag);
                    case 'post':
                        return sprintf('[%s] %s (%s)', $tag, esc_html(get_post($id)?->post_title ?: $parsed->id), esc_html(get_post_type($id) ?: ''));
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
        ', esc_html($this->currentUrl()), implode(PHP_EOL.str_repeat(' ', 18), $cacheTags));
    }

    protected function currentUrl(): string
    {
        return Util::currentUrl();
    }
}
