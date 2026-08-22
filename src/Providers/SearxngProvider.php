<?php

namespace Whilesmart\WebSearch\Providers;

use Whilesmart\WebSearch\Abstracts\HttpSearchProvider;
use Whilesmart\WebSearch\Exceptions\ProviderFailedException;
use Whilesmart\WebSearch\Types\SearchQuery;
use Whilesmart\WebSearch\Types\SearchResult;

/**
 * Self-hosted metasearch. Free and keyless, but its upstream engines challenge
 * traffic from datacentre addresses, so it belongs at the tail of the chain
 * rather than the head.
 */
class SearxngProvider extends HttpSearchProvider
{
    public function name(): string
    {
        return 'searxng';
    }

    public function isConfigured(): bool
    {
        return (bool) $this->option('url');
    }

    public function search(SearchQuery $query): array
    {
        $response = $this->http()
            ->get(rtrim((string) $this->option('url'), '/').'/search', [
                'q' => $query->query,
                'format' => 'json',
                'safesearch' => 1,
            ]);

        if (! $response->successful()) {
            throw new ProviderFailedException($this->name(), 'HTTP '.$response->status());
        }

        $unresponsive = $response->json('unresponsive_engines') ?? [];
        $rows = array_values($response->json('results') ?? []);

        if ($rows === [] && $unresponsive !== []) {
            throw new ProviderFailedException(
                $this->name(),
                'all engines unresponsive: '.json_encode($unresponsive),
            );
        }

        $results = [];

        foreach ($rows as $i => $row) {
            $results[] = new SearchResult(
                (string) ($row['title'] ?? ''),
                (string) ($row['url'] ?? ''),
                (string) ($row['content'] ?? ''),
                $i + 1,
                [$this->name()],
            );
        }

        return array_slice($results, 0, $query->limit);
    }
}
