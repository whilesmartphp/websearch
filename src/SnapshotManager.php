<?php

namespace Whilesmart\WebSearch;

use Whilesmart\WebSearch\Contracts\SnapshotFetcher;
use Whilesmart\WebSearch\Enums\SnapshotFailure;
use Whilesmart\WebSearch\Exceptions\SnapshotFailedException;
use Whilesmart\WebSearch\Types\SiteSnapshot;

class SnapshotManager
{
    /**
     * @param  array<string, SnapshotFetcher>  $fetchers
     */
    public function __construct(private readonly array $fetchers = []) {}

    /**
     * @return array<string, SnapshotFetcher>
     */
    public function all(): array
    {
        return array_filter($this->fetchers, fn (SnapshotFetcher $fetcher): bool => $fetcher->isConfigured());
    }

    public function get(string $name): ?SnapshotFetcher
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @throws SnapshotFailedException
     */
    public function snapshot(string $url, ?string $name = null): SiteSnapshot
    {
        $fetchers = $name === null ? $this->all() : array_filter([$name => $this->get($name)]);

        if ($fetchers === []) {
            throw new SnapshotFailedException($name ?? '*', SnapshotFailure::Unavailable, 'no snapshot fetcher is configured');
        }

        $attempts = [];

        foreach ($fetchers as $key => $fetcher) {
            try {
                return $fetcher->snapshot($url);
            } catch (SnapshotFailedException $e) {
                $attempts[$key] = $e;

                // Crawl4AI's SSRF guard also refuses hosts that do not resolve.
                if ($e->failure === SnapshotFailure::Refused) {
                    break;
                }
            }
        }

        throw count($attempts) === 1 ? reset($attempts) : SnapshotFailedException::fromAttempts($attempts);
    }
}
