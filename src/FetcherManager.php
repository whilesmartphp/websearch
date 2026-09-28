<?php

namespace Whilesmart\WebSearch;

use Whilesmart\WebSearch\Contracts\ContentFetcher;
use Whilesmart\WebSearch\Exceptions\ProviderFailedException;
use Whilesmart\WebSearch\Types\FetchedPage;

class FetcherManager
{
    /**
     * @param  array<string, ContentFetcher>  $fetchers
     */
    public function __construct(private readonly array $fetchers = []) {}

    /**
     * @return array<string, ContentFetcher>
     */
    public function all(): array
    {
        return array_filter($this->fetchers, fn (ContentFetcher $fetcher): bool => $fetcher->isConfigured());
    }

    public function get(string $name): ?ContentFetcher
    {
        return $this->all()[$name] ?? null;
    }

    public function fetch(string $url, ?string $name = null): FetchedPage
    {
        $fetcher = $name === null ? array_values($this->all())[0] ?? null : $this->get($name);

        if ($fetcher === null) {
            throw new ProviderFailedException($name ?? '*', 'no content fetcher is configured');
        }

        return $fetcher->fetch($url);
    }
}
