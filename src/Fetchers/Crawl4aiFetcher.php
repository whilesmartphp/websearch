<?php

namespace Whilesmart\WebSearch\Fetchers;

use Illuminate\Support\Facades\Http;
use Whilesmart\WebSearch\Contracts\ContentFetcher;
use Whilesmart\WebSearch\Exceptions\ProviderFailedException;
use Whilesmart\WebSearch\Types\FetchedPage;

/**
 * Self-hosted Crawl4AI. It only binds beyond loopback when a token is set and
 * then demands that token on every call, so the token is both the network
 * unlock and the credential.
 */
class Crawl4aiFetcher implements ContentFetcher
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly int $timeout = 30,
    ) {}

    public function name(): string
    {
        return 'crawl4ai';
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->config['url'] ?? null);
    }

    public function fetch(string $url): FetchedPage
    {
        $token = $this->config['token'] ?? null;

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->when($token, fn ($http) => $http->withToken((string) $token))
            ->post(rtrim((string) $this->config['url'], '/').'/md', ['url' => $url]);

        if (! $response->successful()) {
            throw new ProviderFailedException($this->name(), 'HTTP '.$response->status());
        }

        // The server has surfaced the markdown under different keys across
        // releases; accept the known shapes before falling back to the body.
        $markdown = (string) (
            $response->json('markdown')
            ?? $response->json('result.markdown')
            ?? $response->json('results.0.markdown')
            ?? $response->body()
        );

        return new FetchedPage($url, (string) ($response->json('title') ?? ''), $markdown, $this->name());
    }
}
