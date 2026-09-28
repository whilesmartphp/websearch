<?php

namespace Whilesmart\WebSearch\Console;

use Illuminate\Console\Command;
use Throwable;
use Whilesmart\WebSearch\Contracts\SearchProvider;
use Whilesmart\WebSearch\Types\SearchQuery;
use Whilesmart\WebSearch\Types\SearchResult;
use Whilesmart\WebSearch\WebSearchServiceProvider;

/**
 * Runs a spread of real queries against every configured provider and reports
 * what each one actually returned. Deployments fail per-provider and per-region
 * rather than all at once, so the only honest check is a live one from the host
 * that will be running the searches.
 */
class ProbeCommand extends Command
{
    protected $signature = 'websearch:probe
        {--query=* : Run these queries instead of the default spread}
        {--limit=8 : Results to request per provider}
        {--json : Emit raw results as JSON}';

    protected $description = 'Query every configured provider live and report what came back';

    /**
     * A spread wide enough to expose provider blind spots: commercial and
     * institutional, English and French, global and local.
     *
     * @var array<string, string>
     */
    private const DEFAULT_QUERIES = [
        'saas intent' => 'teams complaining about CI for monorepos',
        'community intent' => 'site:reddit.com looking for an alternative to Zendesk',
        'local business' => 'fashion boutiques Douala instagram',
        'local business (fr)' => 'boutique de mode Douala contact',
        'tender' => 'UNDP procurement notice consultancy 2026',
        'grant' => 'microgrants global south journalism 2026',
        'visa' => 'Germany job seeker visa requirements 2026',
        'scholarship' => 'fully funded masters scholarship Africa 2027',
        'technical' => 'Laravel MCP server package',
    ];

    public function handle(): int
    {
        $providers = array_filter(
            $this->providers(),
            fn (SearchProvider $p): bool => $p->isConfigured(),
        );

        if ($providers === []) {
            $this->error('No provider is configured. Set at least one API key.');

            return self::FAILURE;
        }

        $this->line('Providers: '.implode(', ', array_map(fn ($p) => $p->name(), $providers)));

        $queries = $this->option('query') ?: self::DEFAULT_QUERIES;
        $limit = (int) $this->option('limit');
        $report = [];

        foreach ($queries as $label => $query) {
            $this->newLine();
            $this->info(is_string($label) ? "{$label}: {$query}" : $query);

            $rows = [];
            $byProvider = [];

            foreach ($providers as $provider) {
                $started = microtime(true);

                try {
                    $results = $provider->search(new SearchQuery($query, $limit));
                    $status = 'ok';
                } catch (Throwable $e) {
                    $results = [];
                    $status = mb_substr($e->getMessage(), 0, 40);
                }

                $ms = (int) round((microtime(true) - $started) * 1000);
                $domains = $this->domains($results);
                $byProvider[$provider->name()] = $domains;

                $rows[] = [
                    'provider' => $provider->name(),
                    'results' => count($results),
                    'domains' => count($domains),
                    'lexical_match' => $this->lexicalMatch($query, $results),
                    'latency_ms' => $ms,
                    'status' => $status,
                    'items' => array_map(fn (SearchResult $result): array => $result->toArray(), $results),
                ];
            }

            $this->table(
                ['provider', 'results', 'domains', 'lexical match', 'latency', 'status'],
                array_map(fn (array $row): array => [
                    $row['provider'],
                    $row['results'],
                    $row['domains'],
                    $row['lexical_match'].'%',
                    $row['latency_ms'].'ms',
                    $row['status'],
                ], $rows),
            );

            foreach ($rows as $row) {
                foreach ($row['items'] as $item) {
                    $this->line('  '.$row['provider'].' | '.$item['title'].' | '.$item['url']);
                }
            }

            $overlap = $this->overlap($byProvider);

            if ($overlap !== null) {
                $this->line("  shared domains across providers: {$overlap}");
            }

            $report[$query] = $rows;
        }

        if ($this->option('json')) {
            $this->newLine();
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<SearchProvider>
     */
    private function providers(): array
    {
        $config = config('websearch');
        $built = [];

        foreach ($config['providers'] ?? [] as $name) {
            $class = WebSearchServiceProvider::$searchProviders[$name] ?? null;

            if ($class !== null) {
                $built[] = new $class($config[$name] ?? [], (int) ($config['timeout'] ?? 15));
            }
        }

        return $built;
    }

    /**
     * Share of results whose title or snippet contains any distinctive term
     * from the query. Crude, but a provider serving decoy results to scrapers
     * still returns a full page of confident-looking rows, and counting rows
     * cannot tell that apart from a page of real ones.
     *
     * @param  list<SearchResult>  $results
     */
    private function lexicalMatch(string $query, array $results): int
    {
        if ($results === []) {
            return 0;
        }

        $stop = ['the', 'for', 'and', 'with', 'from', 'what', 'best', 'looking', 'alternative', 'to', 'a', 'an', 'de', 'du', 'la', 'le'];

        $terms = array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [],
            fn (string $t): bool => mb_strlen($t) > 3 && ! in_array($t, $stop, true),
        );

        if ($terms === []) {
            return 100;
        }

        $hits = 0;

        foreach ($results as $result) {
            $haystack = mb_strtolower($result->title.' '.$result->snippet.' '.$result->url);

            foreach ($terms as $term) {
                if (str_contains($haystack, $term)) {
                    $hits++;

                    break;
                }
            }
        }

        return (int) round($hits / count($results) * 100);
    }

    /**
     * @param  list<SearchResult>  $results
     * @return list<string>
     */
    private function domains(array $results): array
    {
        $hosts = [];

        foreach ($results as $result) {
            $host = parse_url($result->url, PHP_URL_HOST);

            if (is_string($host)) {
                $hosts[] = preg_replace('/^www\./', '', mb_strtolower($host));
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param  array<string, list<string>>  $byProvider
     */
    private function overlap(array $byProvider): ?int
    {
        if (count($byProvider) < 2) {
            return null;
        }

        return count(array_intersect(...array_values($byProvider)));
    }
}
