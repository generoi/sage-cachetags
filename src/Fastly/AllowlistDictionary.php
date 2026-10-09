<?php

namespace Genero\Sage\CacheTags\Fastly;

use Genero\Sage\CacheTags\Util;

/**
 * Syncs the query-param allowlist (see QueryAllowlist) into a Fastly Edge
 * Dictionary as a single comma-separated item, which a static VCL snippet reads
 * to filter the cache key. Dictionary items are versionless — updates hit the
 * live edge in ~30s with no service deploy.
 *
 * Stored as one item per host (key = the site host — see itemKey()) holding the
 * comma-joined list, so a multisite network sharing one service doesn't clobber a
 * single item; the value cap is 8000 chars, ample for hundreds of params. Reuses
 * the FASTLY_SERVICE_ID / FASTLY_API_KEY env the purge invalidator uses.
 */
class AllowlistDictionary
{
    const BASE_URL = 'https://api.fastly.com';

    /** Fastly Edge Dictionary value cap. */
    const MAX_VALUE_LENGTH = 8000;

    protected ?string $dictionaryId = null;

    public function __construct(protected string $dictionary) {}

    /**
     * Dictionary item key — the site's host, so a multisite network sharing one
     * Fastly service stores one allowlist per host instead of clobbering a single
     * shared item. The VCL looks the value up by `req.http.host`.
     */
    public function itemKey(): string
    {
        return (string) (parse_url(home_url(), PHP_URL_HOST) ?: 'params');
    }

    /**
     * True when the comma-joined list would exceed the dictionary value cap — too
     * many attributes/facets for one item. The caller should report it, not push.
     *
     * @param  string[]  $params
     */
    public function exceedsLimit(array $params): bool
    {
        return strlen(implode(',', $params)) > self::MAX_VALUE_LENGTH;
    }

    public function isConfigured(): bool
    {
        return (bool) Util::env('FASTLY_SERVICE_ID') && (bool) Util::env('FASTLY_API_KEY');
    }

    /**
     * The allowlist value currently stored at Fastly (comma-separated), or null
     * when the item, dictionary, or service is unavailable.
     */
    public function current(): ?string
    {
        $id = $this->dictionaryId();
        if ($id === null) {
            return null;
        }

        $item = $this->apiGet("/service/{$this->serviceId()}/dictionary/{$id}/item/".rawurlencode($this->itemKey()));

        return isset($item['item_value']) ? (string) $item['item_value'] : null;
    }

    /**
     * True when the dictionary already holds exactly this list (skip the push).
     *
     * @param  string[]  $params
     */
    public function isSynced(array $params): bool
    {
        return $this->current() === implode(',', $params);
    }

    /**
     * Upsert the allowlist into the dictionary. One bulk PATCH = one API write.
     *
     * @param  string[]  $params
     */
    public function push(array $params): bool
    {
        $id = $this->dictionaryId();
        if ($id === null || $this->exceedsLimit($params)) {
            return false;
        }

        return $this->apiPatch("/service/{$this->serviceId()}/dictionary/{$id}/items", [
            'items' => [[
                'op' => 'upsert',
                'item_key' => $this->itemKey(),
                'item_value' => implode(',', $params),
            ]],
        ]);
    }

    /**
     * Resolve the dictionary's id from its name on the active version (memoised
     * for this request). Null when not configured or the dictionary is missing.
     */
    protected function dictionaryId(): ?string
    {
        if ($this->dictionaryId !== null) {
            return $this->dictionaryId;
        }
        if (! $this->isConfigured()) {
            return null;
        }

        $active = $this->apiGet("/service/{$this->serviceId()}/version/active");
        $version = $active['number'] ?? null;
        if ($version === null) {
            return null;
        }

        $name = rawurlencode($this->dictionary);
        $dictionary = $this->apiGet("/service/{$this->serviceId()}/version/{$version}/dictionary/{$name}");

        return $this->dictionaryId = isset($dictionary['id']) ? (string) $dictionary['id'] : null;
    }

    protected function serviceId(): string
    {
        return (string) Util::env('FASTLY_SERVICE_ID');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function apiGet(string $path): ?array
    {
        $response = wp_remote_get(self::BASE_URL.$path, [
            'headers' => ['Fastly-Key' => Util::env('FASTLY_API_KEY'), 'Accept' => 'application/json'],
            'timeout' => 5,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function apiPatch(string $path, array $body): bool
    {
        $response = wp_remote_request(self::BASE_URL.$path, [
            'method' => 'PATCH',
            'headers' => [
                'Fastly-Key' => Util::env('FASTLY_API_KEY'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => 5,
        ]);

        return ! is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
}
