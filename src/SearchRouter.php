<?php

namespace Whilesmart\WebSearch;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;
use Whilesmart\WebSearch\Contracts\SearchProvider;
use Whilesmart\WebSearch\Contracts\UsageMeter;
use Whilesmart\WebSearch\Enums\RoutingMode;
use Whilesmart\WebSearch\Exceptions\QuotaExhaustedException;
use Whilesmart\WebSearch\Types\SearchQuery;
use Whilesmart\WebSearch\Types\SearchResponse;
use Whilesmart\WebSearch\Types\SearchResult;

class SearchRouter
{
    /**
     * @param  list<SearchProvider>  $providers
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $providers,
        private readonly UsageMeter $meter,
        private readonly CacheRepository $cache,
        private readonly array $config = [],
    ) {}

    public function search(SearchQuery $query, ?RoutingMode $mode = null): SearchResponse
    {
        $mode ??= RoutingMode::tryFrom((string) ($this->config['mode'] ?? 'merge')) ?? RoutingMode::Merge;

        $usable = array_values(array_filter(
            $this->providers,
            fn (SearchProvider $p): bool => $p->isConfigured(),
        ));

        if ($usable === []) {
            return new SearchResponse([], [], ['*' => 'no provider is configured']);
        }

        return $mode === RoutingMode::Waterfall
            ? $this->waterfall($usable, $query)
            : $this->merge($usable, $query);
    }

    /**
     * @param  list<SearchProvider>  $providers
     */
    private function waterfall(array $providers, SearchQuery $query): SearchResponse
    {
        $failures = [];

        foreach ($providers as $provider) {
            try {
                $results = $this->call($provider, $query);
            } catch (Throwable $e) {
                $failures[$provider->name()] = $e->getMessage();

                continue;
            }

            if ($results !== []) {
                return new SearchResponse($results, [$provider->name()], $failures);
            }
        }

        return new SearchResponse([], [], $failures);
    }

    /**
     * @param  list<SearchProvider>  $providers
     */
    private function merge(array $providers, SearchQuery $query): SearchResponse
    {
        $failures = [];
        $used = [];
        $seen = [];

        foreach ($providers as $provider) {
            try {
                $results = $this->call($provider, $query);
            } catch (Throwable $e) {
                $failures[$provider->name()] = $e->getMessage();

                continue;
            }

            $used[] = $provider->name();

            foreach ($results as $result) {
                $key = $result->fingerprint();

                if (! isset($seen[$key])) {
                    $seen[$key] = ['result' => $result, 'providers' => [], 'rank' => $result->rank];

                    continue;
                }

                $seen[$key]['rank'] = min($seen[$key]['rank'], $result->rank);
            }

            foreach ($results as $result) {
                $key = $result->fingerprint();
                $seen[$key]['providers'][] = $provider->name();
            }
        }

        if ($used === []) {
            return new SearchResponse([], [], $failures);
        }

        $merged = [];

        foreach ($seen as $entry) {
            $providerNames = array_values(array_unique($entry['providers']));
            $agreement = count($providerNames) / count($used);
            $position = 1 / max(1, $entry['rank']);

            $merged[] = $entry['result']->withProviders(
                $providerNames,
                round(($agreement * 0.5) + ($position * 0.5), 6),
            );
        }

        usort($merged, fn (SearchResult $a, SearchResult $b): int => ($b->score ?? 0) <=> ($a->score ?? 0));

        return new SearchResponse(array_slice($merged, 0, $query->limit), $used, $failures);
    }

    /**
     * @return list<SearchResult>
     *
     * @throws QuotaExhaustedException
     */
    private function call(SearchProvider $provider, SearchQuery $query): array
    {
        $key = $query->cacheKey($provider->name());

        if ($this->cacheEnabled()) {
            $cached = $this->cache->get($key);

            if (is_array($cached)) {
                return array_map(fn (array $row): SearchResult => SearchResult::fromArray($row), $cached);
            }
        }

        $this->meter->consume($query->tenant, $provider->name());

        $results = $provider->search($query);

        if ($this->cacheEnabled()) {
            $this->cache->put(
                $key,
                array_map(fn (SearchResult $r): array => $r->toArray(), $results),
                (int) ($this->config['cache']['ttl'] ?? 86400),
            );
        }

        return $results;
    }

    private function cacheEnabled(): bool
    {
        return (bool) ($this->config['cache']['enabled'] ?? true);
    }
}
