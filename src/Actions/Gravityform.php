<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tag;
use Genero\Sage\CacheTags\Tags\GravityformTags;

class Gravityform implements Action
{
    /**
     * How long a rendered form's state token stays valid. Gravity Forms 3
     * defaults to 48h, after which the honeypot and state-validated fields
     * (choices, consent, name, address, …) reject every submission from a page
     * cached longer than that. Outlive the ~30d edge TTL instead of purging
     * every form page; a cached page hands everyone the same token anyway.
     */
    public const STATE_LIFESPAN = 31 * DAY_IN_SECONDS;

    /**
     * Field parameter names that prepopulate dynamically from the query string,
     * collected as forms render.
     *
     * @var string[]
     */
    protected array $prepopulateParams = [];

    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_filter('gform_pre_render', [$this, 'addGravityformCacheTags']);
        \add_action('gform_after_save_form', [$this, 'onSaveForm'], 10, 2);
        \add_filter('cachetags/cacheable', [$this, 'isCacheable']);
        \add_filter('gform_state_lifespan', [$this, 'stateLifespan']);
    }

    /**
     * @param  int|string  $lifespan  Seconds, from GF or an earlier filter.
     */
    public function stateLifespan($lifespan): int
    {
        return max((int) $lifespan, self::STATE_LIFESPAN);
    }

    /**
     * @param  array<string, mixed>|bool  $form
     * @return array<string, mixed>|bool
     */
    public function addGravityformCacheTags(array|bool $form): array|bool
    {
        if (! is_array($form)) {
            return $form;
        }

        $this->cacheTags->add(GravityformTags::forms($form['id']));

        foreach ($form['fields'] as $field) {
            // 2.9.24+ uses a nonce for file uploads; the state token is kept
            // alive by stateLifespan() instead, so other forms need no purge.
            if ($field['type'] === 'fileupload') {
                $this->cacheTags->add(Tag::nonce());
            }

            $this->collectPrepopulateParams($field);
        }

        return $form;
    }

    /**
     * A form prepopulated from the query string renders per-visitor content
     * (often PII like email/name, or unbounded campaign values). Rather than
     * key the cache on — and store — those values, the page is non-cacheable
     * whenever a prepopulate parameter is present in the request.
     */
    public function isCacheable(bool $cacheable): bool
    {
        if (! $cacheable || empty($this->prepopulateParams)) {
            return $cacheable;
        }

        return empty(array_intersect_key($_GET, array_flip($this->prepopulateParams)));
    }

    /**
     * @param  array<string, mixed>  $field  A GF_Field (ArrayAccess)
     */
    protected function collectPrepopulateParams($field): void
    {
        if (empty($field['allowsPrepopulate'])) {
            return;
        }

        if (! empty($field['inputName'])) {
            $this->prepopulateParams[] = $field['inputName'];
        }

        // Multi-input fields (name, address, …) prepopulate per sub-input.
        foreach ((array) ($field['inputs'] ?? []) as $input) {
            if (! empty($input['name'])) {
                $this->prepopulateParams[] = $input['name'];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public function onSaveForm(array $form, bool $isNew): void
    {
        if ($isNew) {
            return;
        }

        $this->cacheTags->clear([
            ...GravityformTags::forms($form['id']),
        ]);
    }
}
