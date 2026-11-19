<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Concerns\SavesAtShutdown;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tag;
use Genero\Sage\CacheTags\Tags\CoreTags;
use WP;

/**
 * Tag and store XML sitemaps, core's (wp-sitemap*.xml) and Yoast's
 * (*sitemap*.xml), which print no wp_footer. Remove from the `action` config
 * to opt out (and keep a page cache from storing sitemaps, or they're never
 * purged).
 */
class Sitemap implements Action
{
    use SavesAtShutdown;

    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        // Both route through the `sitemap` query var, and Yoast prints and exits
        // on pre_get_posts, before template_redirect.
        \add_action('parse_request', [$this, 'addSitemapCacheTags']);
        \add_action('shutdown', [$this, 'saveSitemapCacheTags'], 0);
        // A taxonomy sitemap lists only terms with posts; an importer can
        // assign terms without saving the post.
        \add_action('set_object_terms', [$this, 'onTermSet'], 10, 6);
    }

    /**
     * Only when the object's terms changed: importers re-save unchanged term
     * sets, and taxonomy:{tax}:any is on every page listing the taxonomy.
     *
     * @param  array<int|string>  $terms
     * @param  int[]  $taxonomyIds
     * @param  int[]  $oldTaxonomyIds
     */
    public function onTermSet(int $objectId, array $terms, array $taxonomyIds, string $taxonomy, bool $append = false, array $oldTaxonomyIds = []): void
    {
        if (! CoreTags::isCacheableTaxonomy($taxonomy)) {
            return;
        }

        $new = array_map('intval', $taxonomyIds);
        $old = array_map('intval', $oldTaxonomyIds);
        $removed = $append ? [] : array_diff($old, $new);
        if (! array_diff($new, $old) && ! $removed) {
            return;
        }

        $this->cacheTags->clear(CoreTags::anyTerm($taxonomy));
    }

    /**
     * Tags for a taxonomy's sitemap: its terms, and the posts that decide
     * which terms are listed (only those with posts) and, in Yoast's, their
     * dates (each term's newest post).
     *
     * @return Tag[]
     */
    protected function taxonomyTags(string $taxonomy): array
    {
        $objectTypes = get_taxonomy($taxonomy)->object_type ?? [];

        return [...CoreTags::anyTerm($taxonomy), ...CoreTags::anyArchive($objectTypes)];
    }

    /**
     * Tag a sitemap with only what it lists. A type's sitemap prints each
     * entry's last modified date, so any change to one of them changes it.
     */
    public function addSitemapCacheTags(WP $wp): void
    {
        $sitemap = (string) ($wp->query_vars['sitemap'] ?? '');
        if ($sitemap === '') {
            return;
        }
        $subtype = (string) ($wp->query_vars['sitemap-subtype'] ?? '') ?: 'any';

        $this->cacheTags->add(match (true) {
            // Core's index lists a sub-sitemap per page of entries, undated.
            $sitemap === 'index' => [...CoreTags::archive('any'), ...CoreTags::taxonomy('any')],
            // Core's per-type sitemaps (wp-sitemap-{provider}-{subtype}-{n}.xml).
            $sitemap === 'posts' => CoreTags::anyArchive($subtype),
            $sitemap === 'taxonomies' => $this->taxonomyTags($subtype),
            // Core's and Yoast's author sitemaps list users with published posts.
            $sitemap === 'users', $sitemap === 'author' => [...CoreTags::anyUser('any'), ...CoreTags::archive('any')],
            // Yoast's per-type sitemaps ({type}-sitemap{n}.xml).
            post_type_exists($sitemap) => CoreTags::anyArchive($sitemap),
            taxonomy_exists($sitemap) => $this->taxonomyTags($sitemap),
            // Yoast's index (sitemap=1) dates each sub-sitemap by its newest
            // entry, and any other sitemap type is unknown: tag every change.
            default => [...CoreTags::anyArchive('any'), ...CoreTags::anyTerm('any')],
        });
    }

    public function saveSitemapCacheTags(): void
    {
        if (! get_query_var('sitemap')) {
            return;
        }

        // Yoast exits before template_redirect, where the base tag (the
        // site-wide flush), the site tag and the language tag are added.
        if (! did_action('template_redirect')) {
            $this->cacheTags->action(BaseTag::class)?->addBaseTag();
            $this->cacheTags->action(Site::class)?->addSiteTag();
            $this->cacheTags->action(Polylang::class)?->addLanguageTag();
        }

        $this->saveAtShutdown();
    }
}
