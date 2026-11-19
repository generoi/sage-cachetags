<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Concerns\SavesAtShutdown;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tag;
use Genero\Sage\CacheTags\Tags\CoreTags;
use WP_Comment;
use WP_Post;

/**
 * Tag and store RSS/Atom feeds, which Core skips and which print no wp_footer.
 * Remove from the `action` config to opt out (and keep a page cache from
 * storing feeds, or they're never purged).
 */
class Feed implements Action
{
    use SavesAtShutdown;

    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_action('template_redirect', [$this, 'addFeedCacheTags']);
        \add_action('shutdown', [$this, 'saveFeedCacheTags'], 0);

        // The site-wide comments feed lists the latest comments on any post.
        \add_action('comment_post', [$this, 'onCommentPost'], 10, 2);
        \add_action('transition_comment_status', [$this, 'onCommentStatusTransition'], 10, 3);
        \add_action('edit_comment', [$this, 'clearCommentsFeed']);
        \add_action('deleted_comment', [$this, 'clearCommentsFeed']);
    }

    /**
     * A feed prints its items itself, not through blocks or teasers that would
     * tag them: their content, author, and (RSS `<category>`) categories and
     * tags. Its listing is tagged like its HTML page, except that WP_Query never
     * sets is_home() on a feed, so the main feed is tagged here too.
     */
    public function addFeedCacheTags(): void
    {
        if (! is_feed()) {
            return;
        }

        global $wp_query;
        $items = array_filter($wp_query->posts ?? [], fn ($item) => $item instanceof WP_Post);

        $tags = CoreTags::posts($items);
        foreach ($items as $item) {
            $tags = [
                ...$tags,
                ...CoreTags::users((int) $item->post_author),
                ...CoreTags::terms(get_the_terms($item, 'category') ?: []),
                ...CoreTags::terms(get_the_terms($item, 'post_tag') ?: []),
            ];
        }

        $listing = match (true) {
            is_comment_feed() && ! is_singular() => [Tag::of('comment', 'any')],
            is_search() => CoreTags::archive('post'),
            is_singular() => CoreTags::queriedObject(),
            is_category(), is_tag(), is_tax() => [
                ...CoreTags::queriedObject(),
                ...CoreTags::taxonomy(\get_queried_object()->taxonomy),
            ],
            is_post_type_archive() => CoreTags::archive(get_query_var('post_type')),
            // The HTML author page gets its listing tag from its query block;
            // the feed has none, and a new post doesn't clear user:{id}.
            is_author() => [
                ...CoreTags::users(get_query_var('author')),
                ...CoreTags::archive(get_query_var('post_type') ?: 'post'),
            ],
            default => CoreTags::archive(get_query_var('post_type') ?: 'post'),
        };

        $this->cacheTags->add([
            ...$tags,
            ...$listing,
            // The channel's title and description.
            ...CoreTags::option(['blogname', 'blogdescription']),
        ]);
    }

    public function saveFeedCacheTags(): void
    {
        if (is_feed()) {
            $this->saveAtShutdown();
        }
    }

    /**
     * @param  int|string  $approved
     */
    public function onCommentPost(int $commentId, $approved): void
    {
        if ((int) $approved === 1) {
            $this->clearCommentsFeed();
        }
    }

    public function onCommentStatusTransition(string $newStatus, string $oldStatus, WP_Comment $comment): void
    {
        if ($newStatus === 'approved' || $oldStatus === 'approved') {
            $this->clearCommentsFeed();
        }
    }

    public function clearCommentsFeed(): void
    {
        $this->cacheTags->clear([Tag::of('comment', 'any')]);
    }
}
