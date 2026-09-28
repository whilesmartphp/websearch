<?php

namespace Whilesmart\WebSearch;

use GuzzleHttp\Promise\Utils;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;
use Whilesmart\WebSearch\Contracts\ConcurrentSearchProvider;
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
        $used = [];

        foreach ($providers as $provider) {
            try {
                $results = $this->call($provider, $query);
            } catch (Throwable $e) {
                $failures[$provider->name()] = $e->getMessage();

                continue;
            }

            $used[] = $provider->name();

            if ($results !== []) {
                return new SearchResponse($results, $used, $failures);
            }
        }

        return new SearchResponse([], $used, $failures);
    }

    /**
     * @param  list<SearchProvider>  $providers
     */
    private function merge(array $providers, SearchQuery $query): SearchResponse
    {
        [$answers, $failures] = $this->concurrentCalls($providers, $query);
        $used = array_keys($answers);
        $seen = [];

        foreach ($answers as $provider => $results) {
            foreach ($results as $result) {
                $key = $result->fingerprint();

                if (! isset($seen[$key])) {
                    $seen[$key] = ['result' => $result, 'providers' => [], 'rank' => $result->rank, 'score' => 0.0];
                }

                $seen[$key]['rank'] = min($seen[$key]['rank'], $result->rank);
                $seen[$key]['providers'][] = $provider;
                $seen[$key]['score'] += 1 / (60 + max(1, $result->rank));
            }
        }

        if ($used === []) {
            return new SearchResponse([], [], $failures);
        }

        $merged = [];

        foreach ($seen as $entry) {
            $providerNames = array_values(array_unique($entry['providers']));
            $merged[] = $entry['result']->withProviders(
                $providerNames,
                round($entry['score'], 8),
                $entry['rank'],
            );
        }

        usort($merged, function (SearchResult $a, SearchResult $b): int {
            return (($b->score ?? 0) <=> ($a->score ?? 0))
                ?: ($a->rank <=> $b->rank)
                ?: ($a->fingerprint() <=> $b->fingerprint());
        });

        return new SearchResponse(array_slice($merged, 0, $query->limit), $used, $failures);
    }

    /**
     * @param  list<SearchProvider>  $providers
     * @return array{array<string, list<SearchResult>>, array<string, string>}
     */
    private function concurrentCalls(array $providers, SearchQuery $query): array
    {
        $answers = [];
        $failures = [];
        $promises = [];

        foreach ($providers as $provider) {
            try {
                $cached = $this->cached($provider, $query);

                if ($cached !== null) {
                    $answers[$provider->name()] = $cached;

                    continue;
                }

                $this->meter->consume($query->tenant, $provider->name());

                if ($provider instanceof ConcurrentSearchProvider) {
                    $promises[$provider->name()] = $provider->searchAsync($query)->then(
                        fn (array $results): array => $this->store($provider, $query, $results),
                    );

                    continue;
                }

                $answers[$provider->name()] = $this->store($provider, $query, $provider->search($query));
            } catch (Throwable $e) {
                $failures[$provider->name()] = $e->getMessage();
            }
        }

        if ($promises !== []) {
            foreach (Utils::settle($promises)->wait() as $provider => $settled) {
                if ($settled['state'] === 'fulfilled') {
                    $answers[$provider] = $settled['value'];
                } else {
                    $reason = $settled['reason'];
                    $failures[$provider] = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;
                }
            }
        }

        $order = array_flip(array_map(fn (SearchProvider $provider): string => $provider->name(), $providers));
        uksort($answers, fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
        uksort($failures, fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return [$answers, $failures];
    }

    /**
     * @return list<SearchResult>
     *
     * @throws QuotaExhaustedException
     */
    private function call(SearchProvider $provider, SearchQuery $query): array
    {
        $cached = $this->cached($provider, $query);

        if ($cached !== null) {
            return $cached;
        }

        $this->meter->consume($query->tenant, $provider->name());

        return $this->store($provider, $query, $provider->search($query));
    }

    /**
     * @return list<SearchResult>|null
     */
    private function cached(SearchProvider $provider, SearchQuery $query): ?array
    {
        if (! $this->cacheEnabled()) {
            return null;
        }

        $cached = $this->cache->get($query->cacheKey($provider->name()));

        return is_array($cached)
            ? array_map(fn (array $row): SearchResult => SearchResult::fromArray($row), $cached)
            : null;
    }

    /**
     * @param  list<SearchResult>  $results
     * @return list<SearchResult>
     */
    private function store(SearchProvider $provider, SearchQuery $query, array $results): array
    {
        if ($this->cacheEnabled()) {
            $this->cache->put(
                $query->cacheKey($provider->name()),
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
