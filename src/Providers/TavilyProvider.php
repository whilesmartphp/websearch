<?php

namespace Whilesmart\WebSearch\Providers;

use Whilesmart\WebSearch\Abstracts\HttpSearchProvider;
use Whilesmart\WebSearch\Exceptions\ProviderFailedException;
use Whilesmart\WebSearch\Types\SearchQuery;
use Whilesmart\WebSearch\Types\SearchResult;

class TavilyProvider extends HttpSearchProvider
{
    public function name(): string
    {
        return 'tavily';
    }

    public function isConfigured(): bool
    {
        return (bool) $this->option('api_key');
    }

    public function search(SearchQuery $query): array
    {
        $response = $this->http()
            ->withToken((string) $this->option('api_key'))
            ->post($this->option('endpoint', 'https://api.tavily.com/search'), [
                'query' => $query->query,
                'max_results' => min($query->limit, 20),
                'search_depth' => (string) $this->option('search_depth', 'basic'),
            ]);

        if (! $response->successful()) {
            throw new ProviderFailedException($this->name(), 'HTTP '.$response->status());
        }

        $results = [];

        foreach (array_values($response->json('results') ?? []) as $i => $row) {
            $results[] = new SearchResult(
                (string) ($row['title'] ?? ''),
                (string) ($row['url'] ?? ''),
                (string) ($row['content'] ?? ''),
                $i + 1,
                [$this->name()],
                isset($row['score']) ? (float) $row['score'] : null,
            );
        }

        return array_slice($results, 0, $query->limit);
    }
}
