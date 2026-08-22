<?php

namespace Whilesmart\WebSearch\Providers;

use Whilesmart\WebSearch\Abstracts\HttpSearchProvider;
use Whilesmart\WebSearch\Exceptions\ProviderFailedException;
use Whilesmart\WebSearch\Types\SearchQuery;
use Whilesmart\WebSearch\Types\SearchResult;

class BraveProvider extends HttpSearchProvider
{
    public function name(): string
    {
        return 'brave';
    }

    public function isConfigured(): bool
    {
        return (bool) $this->option('api_key');
    }

    public function search(SearchQuery $query): array
    {
        $response = $this->http()
            ->withHeaders(['X-Subscription-Token' => (string) $this->option('api_key')])
            ->get($this->option('endpoint', 'https://api.search.brave.com/res/v1/web/search'), array_filter([
                'q' => $query->query,
                'count' => min($query->limit, 20),
                'country' => $query->country,
                'safesearch' => 'moderate',
            ], fn ($v) => $v !== null));

        if (! $response->successful()) {
            throw new ProviderFailedException($this->name(), 'HTTP '.$response->status());
        }

        $results = [];

        foreach (array_values($response->json('web.results') ?? []) as $i => $row) {
            $results[] = new SearchResult(
                (string) ($row['title'] ?? ''),
                (string) ($row['url'] ?? ''),
                (string) ($row['description'] ?? ''),
                $i + 1,
                [$this->name()],
            );
        }

        return array_slice($results, 0, $query->limit);
    }
}
