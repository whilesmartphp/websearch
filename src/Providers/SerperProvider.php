<?php

namespace Whilesmart\WebSearch\Providers;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Whilesmart\WebSearch\Abstracts\HttpSearchProvider;
use Whilesmart\WebSearch\Exceptions\ProviderFailedException;
use Whilesmart\WebSearch\Types\SearchQuery;
use Whilesmart\WebSearch\Types\SearchResult;

class SerperProvider extends HttpSearchProvider
{
    public function name(): string
    {
        return 'serper';
    }

    public function isConfigured(): bool
    {
        return (bool) $this->option('api_key');
    }

    public function search(SearchQuery $query): array
    {
        return $this->searchAsync($query)->wait();
    }

    public function searchAsync(SearchQuery $query): PromiseInterface
    {
        return $this->http(true)
            ->withHeaders(['X-API-KEY' => (string) $this->option('api_key')])
            ->post($this->option('endpoint', 'https://google.serper.dev/search'), array_filter([
                'q' => $query->query,
                'num' => min($query->limit, 20),
                'gl' => $query->country,
                'hl' => $query->language,
            ], fn ($value) => $value !== null))
            ->then(fn (Response $response): array => $this->results($response, $query));
    }

    private function results(Response $response, SearchQuery $query): array
    {
        if (! $response->successful()) {
            throw new ProviderFailedException($this->name(), 'HTTP '.$response->status());
        }

        $results = [];

        foreach (array_values($response->json('organic') ?? []) as $i => $row) {
            $results[] = new SearchResult(
                (string) ($row['title'] ?? ''),
                (string) ($row['link'] ?? ''),
                (string) ($row['snippet'] ?? ''),
                (int) ($row['position'] ?? $i + 1),
                [$this->name()],
            );
        }

        return array_slice($results, 0, $query->limit);
    }
}
