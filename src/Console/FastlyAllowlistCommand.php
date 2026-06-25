<?php

namespace Genero\Sage\CacheTags\Console;

use Genero\Sage\CacheTags\Fastly\AllowlistDictionary;
use Genero\Sage\CacheTags\Fastly\QueryAllowlist;
use Roots\Acorn\Console\Commands\Command;

class FastlyAllowlistCommand extends Command
{
    /**
     * @var string
     */
    protected $name = 'cachetags:fastly-allowlist';

    /**
     * @var string
     */
    protected $description = 'Inspect and manage the Fastly query-param allowlist';

    protected $signature = 'cachetags:fastly-allowlist
        {action=preview : preview | status | sync}
        {--dictionary= : Override the configured Edge Dictionary name}
        {--force : Push even when the dictionary already matches}';

    public function handle(): int
    {
        $params = QueryAllowlist::collect();
        $action = (string) $this->argument('action');

        if ($action === 'preview') {
            $this->line(implode(PHP_EOL, $params));
            $this->info(sprintf('%d cache-significant params. Add more via the cachetags/fastly-allowed-query-params filter, then sync.', count($params)));

            return self::SUCCESS;
        }

        $name = $this->option('dictionary') ?: config('cachetags.fastly-allowlist-dictionary');
        if (! $name) {
            $this->error('No dictionary configured (cachetags.fastly-allowlist-dictionary) — or pass --dictionary.');

            return self::FAILURE;
        }

        $dictionary = new AllowlistDictionary($name);
        if (! $dictionary->isConfigured()) {
            $this->error('FASTLY_SERVICE_ID / FASTLY_API_KEY are not set.');

            return self::FAILURE;
        }

        if ($action === 'status') {
            $current = $dictionary->current();
            $this->line('Computed : '.implode(',', $params));

            if ($current === null) {
                $this->warn('Dictionary item unavailable — check the dictionary exists and the token has write_dictionaries scope.');

                return self::SUCCESS;
            }

            $this->line('At Fastly: '.$current);
            $this->line($current === implode(',', $params) ? 'In sync.' : 'OUT OF SYNC — run sync.');

            return self::SUCCESS;
        }

        if ($action === 'sync') {
            if ($dictionary->exceedsLimit($params)) {
                $this->error(sprintf(
                    'Allowlist too long (%d chars > %d) — too many attributes/facets for one dictionary item.',
                    strlen(implode(',', $params)),
                    AllowlistDictionary::MAX_VALUE_LENGTH
                ));

                return self::FAILURE;
            }

            if (! $this->option('force') && $dictionary->isSynced($params)) {
                $this->info('Already in sync; nothing to push.');

                return self::SUCCESS;
            }

            if ($dictionary->push($params)) {
                $this->info(sprintf('Synced %d params to Fastly.', count($params)));

                return self::SUCCESS;
            }

            $this->error('Push failed — check FASTLY_SERVICE_ID / FASTLY_API_KEY and the dictionary name.');

            return self::FAILURE;
        }

        $this->error("Unknown action '{$action}'; use preview, status or sync.");

        return self::FAILURE;
    }
}
